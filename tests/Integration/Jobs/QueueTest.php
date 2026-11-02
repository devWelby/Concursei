<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Jobs;

use Concursei\Modules\Jobs\JobRegistry;
use Concursei\Modules\Jobs\Queue;
use Concursei\Modules\Jobs\Worker;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\ScriptedJobHandler;
use DateTimeImmutable;
use InvalidArgumentException;

final class QueueTest extends IntegrationTestCase
{
    private Queue $queue;
    private Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:00:00'));
        $this->app->container->get(JobRegistry::class)->register('test.scripted', ScriptedJobHandler::class);
        $this->queue = $this->app->container->get(Queue::class);
        $this->worker = $this->app->container->get(Worker::class);
    }

    public function testDispatchValidatesTypePayloadAndDeduplicatesActiveJobs(): void
    {
        try {
            $this->queue->dispatch('test.desconhecido');
            self::fail('Tipo fora da allowlist não pode entrar na fila');
        } catch (InvalidArgumentException) {
        }
        try {
            $this->queue->dispatch('test.scripted', ['opcoes' => ['api_key' => 'x']]);
            self::fail('Payload com segredo deve ser recusado');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('segredo', $e->getMessage());
        }
        try {
            $this->queue->dispatch('test.scripted', ['value' => str_repeat('a', 20000)]);
            self::fail('Payload grande deve ser recusado');
        } catch (InvalidArgumentException) {
        }

        $first = $this->queue->dispatch('test.scripted', ['value' => 'a'], 'fonte:1');
        self::assertSame($first, $this->queue->dispatch('test.scripted', ['value' => 'b'], 'fonte:1'), 'Mesmo job ativo é reaproveitado');
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'));

        self::assertSame(['completed' => 1, 'retry' => 0, 'failed' => 0], $this->worker->run(10, 30));
        $row = $this->queue->find($first);
        self::assertSame('completed', $row['status']);
        self::assertNull($row['unique_key']);
        self::assertSame('{"echo":"a"}', $row['result']);
        self::assertNotSame($first, $this->queue->dispatch('test.scripted', [], 'fonte:1'), 'Depois de concluído, a chave pode ser usada de novo');
    }

    public function testRetriesWithBackoffThenFailsWithRedactedError(): void
    {
        $id = $this->queue->dispatch('test.scripted', ['mode' => 'retry'], null, null, 0, 2);
        self::assertSame(['completed' => 0, 'retry' => 1, 'failed' => 0], $this->worker->run(5, 30));
        $row = $this->queue->find($id);
        self::assertSame('retry_scheduled', $row['status']);
        self::assertSame('Fonte indisponível (HTTP 503)', $row['last_error']);
        self::assertGreaterThanOrEqual('2027-03-10 12:00:30', $row['available_at']);
        self::assertNull($this->queue->claim(), 'Não roda antes do horário de nova tentativa');

        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:05:00'));
        self::assertSame(['completed' => 0, 'retry' => 0, 'failed' => 1], $this->worker->run(5, 30));
        self::assertSame('failed', $this->queue->find($id)['status']);

        $crash = $this->queue->dispatch('test.scripted', ['mode' => 'crash'], null, null, 0, 1);
        $this->worker->run(5, 30);
        $row = $this->queue->find($crash);
        self::assertSame('failed', $row['status']);
        self::assertSame('Erro interno (LogicException).', $row['last_error'], 'Detalhes internos não vão para a fila');

        $after = $this->queue->dispatch('test.scripted', ['mode' => 'retry_after']);
        $this->worker->run(5, 30);
        self::assertSame('2027-03-10 12:07:00', $this->queue->find($after)['available_at'], 'Respeita o tempo pedido (Retry-After)');

        $permanent = $this->queue->dispatch('test.scripted', ['mode' => 'fail']);
        $this->worker->run(5, 30);
        self::assertSame(['failed', '1'], [$this->queue->find($permanent)['status'], (string) $this->queue->find($permanent)['attempts']], 'Falha definitiva não tenta de novo');
    }

    public function testExpiredLeaseIsReclaimedAndOldWorkerCannotOverwrite(): void
    {
        $id = $this->queue->dispatch('test.scripted', ['value' => 'x'], null, null, 0, 2);
        $old = $this->queue->claim(60);
        self::assertNotNull($old);
        self::assertNull($this->queue->claim(), 'Job em execução com lease válido não é reservado de novo');

        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:01:01'));
        $new = $this->queue->claim();
        self::assertNotNull($new);
        self::assertSame($id, $new->id);
        self::assertSame(2, $new->attempts);

        self::assertFalse($this->queue->complete($old, ['echo' => 'velho']), 'Token antigo não finaliza');
        self::assertTrue($this->queue->complete($new, ['echo' => 'novo']));
        self::assertSame('{"echo":"novo"}', $this->queue->find($id)['result']);
    }

    public function testInterruptedLastAttemptIsMarkedFailed(): void
    {
        $id = $this->queue->dispatch('test.scripted', [], null, null, 0, 1);
        self::assertNotNull($this->queue->claim(60));
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:02:00'));
        self::assertNull($this->queue->claim());
        $row = $this->queue->find($id);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('interrompida', $row['last_error']);
    }

    public function testWorkerStopsAtMaxJobsAndAdminActions(): void
    {
        foreach (range(1, 3) as $i) {
            $this->queue->dispatch('test.scripted', ['value' => (string) $i]);
        }
        self::assertSame(2, $this->worker->run(2, 30)['completed']);
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'pending'"));

        $pending = (int) $this->db->scalar("SELECT id FROM jobs WHERE status = 'pending'");
        self::assertTrue($this->queue->cancel($pending));
        self::assertFalse($this->queue->cancel($pending));
        self::assertTrue($this->queue->requeue($pending));
        self::assertSame(['pending', '0'], [$this->queue->find($pending)['status'], (string) $this->queue->find($pending)['attempts']]);

        $done = (int) $this->db->scalar("SELECT id FROM jobs WHERE status = 'completed' ORDER BY id LIMIT 1");
        self::assertFalse($this->queue->requeue($done), 'Concluído não volta para a fila');

        $this->clock->setNow(new DateTimeImmutable('2027-03-18 12:00:00'));
        self::assertSame(2, $this->queue->purge(), 'Concluídos há mais de 7 dias são removidos');
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'));
    }
}
