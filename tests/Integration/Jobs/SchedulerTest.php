<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Jobs;

use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Core\Logging\Logger;
use Concursei\Modules\Jobs\JobRegistry;
use Concursei\Modules\Jobs\Queue;
use Concursei\Modules\Jobs\Scheduler;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use Concursei\Tests\Support\ScriptedJobHandler;
use DateTimeImmutable;

final class SchedulerTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 12:00 UTC = 09:00 em Brasília.
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:00:00'));
    }

    private function scheduler(array $schedule): Scheduler
    {
        $c = $this->app->container;
        $config = $c->get(Config::class)->with('jobs.schedule', $schedule);
        return new Scheduler($c->get(Database::class), $this->clock, $config, $c->get(Logger::class));
    }

    private function runner(int $code = 0): \Closure
    {
        return function (string $command, array $options) use ($code): int {
            $this->ran[] = $command . ($options !== [] ? ' ' . json_encode($options) : '');
            return $command === 'falha:sempre' ? 1 : $code;
        };
    }

    public function testIntervalAndDailyTasksRunOnlyWhenDue(): void
    {
        $s = $this->scheduler([
            'queue:work' => ['every_minutes' => 5, 'options' => ['max-jobs' => 3]],
            'catalog:refresh' => ['daily_at' => '00:05'],
            'tokens:purge' => ['daily_at' => '10:00'],
        ]);
        self::assertSame(['queue:work' => 'ok', 'catalog:refresh' => 'ok', 'tokens:purge' => 'skipped'], $s->runDue($this->runner()));
        self::assertSame(['queue:work {"max-jobs":3}', 'catalog:refresh'], $this->ran);

        // 4min40s depois: dentro da folga de 30 s para o atraso do Cron, o intervalo de 5 min já conta.
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:04:40'));
        self::assertSame(['queue:work' => 'ok', 'catalog:refresh' => 'skipped', 'tokens:purge' => 'skipped'], $s->runDue($this->runner()));
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:06:00'));
        self::assertSame('skipped', $s->runDue($this->runner())['queue:work']);

        // 13:00 UTC = 10:00 local: a tarefa das 10h roda uma vez no dia local.
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 13:00:00'));
        self::assertSame('ok', $s->runDue($this->runner())['tokens:purge']);
        $this->clock->setNow(new DateTimeImmutable('2027-03-11 02:59:00'));
        self::assertSame('skipped', $s->runDue($this->runner())['catalog:refresh'], '23:59 local ainda é o mesmo dia');
        $this->clock->setNow(new DateTimeImmutable('2027-03-11 03:06:00'));
        self::assertSame('ok', $s->runDue($this->runner())['catalog:refresh'], '00:06 local do dia seguinte');
    }

    public function testHeldLockPreventsOverlapAndStaleLockIsRecovered(): void
    {
        $s = $this->scheduler(['catalog:refresh' => ['every_minutes' => 1]]);
        $this->db->run("INSERT INTO scheduled_tasks (name, lock_token, locked_until, last_status) VALUES ('catalog:refresh', 'outro-processo-xxxxxxxxxxxxxxxxx', '2027-03-10 12:10:00', 'running')");
        self::assertSame(['catalog:refresh' => 'locked'], $s->runDue($this->runner()));
        self::assertSame([], $this->ran);

        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:10:01'));
        self::assertSame(['catalog:refresh' => 'ok'], $s->runDue($this->runner()));

        $this->db->run("UPDATE scheduled_tasks SET lock_token = 'agendador-em-curso-xxxxxxxxxxxxx', locked_until = '2027-03-10 12:30:00' WHERE name = 'schedule:run'");
        self::assertSame(['schedule:run' => 'locked'], $s->runDue($this->runner()), 'Dois agendadores não rodam ao mesmo tempo');
    }

    public function testFailureIsRecordedAsHeartbeat(): void
    {
        $s = $this->scheduler(['falha:sempre' => ['every_minutes' => 1], 'mail:send-pending' => ['every_minutes' => 1]]);
        self::assertSame(['falha:sempre' => 'failed', 'mail:send-pending' => 'ok'], $s->runDue($this->runner()));
        $beats = array_column($s->heartbeats(), null, 'name');
        self::assertSame('failed', $beats['falha:sempre']['last_status']);
        self::assertSame('código de saída 1', $beats['falha:sempre']['last_message']);
        self::assertSame('ok', $beats['mail:send-pending']['last_status']);
        self::assertSame('1 tarefa(s) com falha', $beats['schedule:run']['last_message']);
        self::assertNull($beats['schedule:run']['lock_token']);
    }

    public function testHealthPanelIsAdminOnlyAndRequeuesDeadLetters(): void
    {
        $this->app->container->get(JobRegistry::class)->register('test.scripted', ScriptedJobHandler::class);
        $queue = $this->app->container->get(Queue::class);
        $id = $queue->dispatch('test.scripted', ['mode' => 'fail']);
        $queue->fail($queue->claim(), 'Documento inválido');

        $this->loginAs($this->staff()->id);
        self::assertSame(403, $this->request('GET', '/admin/sistema')->status(), 'Editor não gerencia a fila');
        self::assertStringNotContainsString('/admin/sistema', $this->request('GET', '/admin')->body());

        $this->session()->invalidate();
        $this->loginAs($this->staff('admin@example.com', 'admin')->id);
        $page = $this->request('GET', '/admin/sistema');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Documento inválido', $page->body());
        self::assertStringContainsString('não concluiu nenhuma execução', $page->body(), 'Avisa quando o Cron não roda');

        $this->assertRedirect($this->post('/admin/sistema/jobs/' . $id . '/reprocessar'), '/admin/sistema');
        self::assertSame('pending', $queue->find($id)['status']);
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'job.requeue'"));
        self::assertSame(404, $this->post('/admin/sistema/jobs/999/cancelar')->status());
    }
}
