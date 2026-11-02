<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

use Closure;
use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Core\Logging\Logger;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Agenda do Cron (D-016): um único Cron chama schedule:run; cada tarefa vencida roda com lock próprio.
 * O lock é condicionado ao último início lido, então duas execuções sobrepostas não rodam a mesma tarefa
 * duas vezes. Lock vencido (processo morto) é recuperado na próxima execução.
 */
final class Scheduler
{
    public const SELF = 'schedule:run';

    /** Folga para o atraso natural do Cron não pular uma execução. */
    private const SLACK_SECONDS = 30;

    public function __construct(private Database $db, private Clock $clock, private Config $config, private Logger $logger)
    {
    }

    /**
     * @param Closure(string, array<string,scalar>):int $runner executa um comando e devolve o código de saída
     * @return array<string,'ok'|'failed'|'skipped'|'locked'>
     */
    public function runDue(Closure $runner, ?string $only = null): array
    {
        $report = [];
        $self = $this->acquire(self::SELF, $this->state(self::SELF));
        if ($self === null) {
            return [self::SELF => 'locked'];
        }
        $started = microtime(true);
        $failures = 0;
        foreach ($this->tasks() as $name => $def) {
            if ($only !== null && $name !== $only) {
                continue;
            }
            $state = $this->state($name);
            if ($only === null && !$this->isDue($def, $state['last_started_at'] ?? null)) {
                $report[$name] = 'skipped';
                continue;
            }
            $token = $this->acquire($name, $state);
            if ($token === null) {
                $report[$name] = 'locked';
                continue;
            }
            $t = microtime(true);
            try {
                $code = $runner($name, $def['options'] ?? []);
                $message = $code === 0 ? 'ok' : "código de saída {$code}";
            } catch (Throwable $e) {
                $this->logger->exception($e, 'Falha em tarefa agendada', ['task' => $name]);
                $code = 1;
                $message = 'exceção: ' . (new \ReflectionClass($e))->getShortName();
            }
            $report[$name] = $code === 0 ? 'ok' : 'failed';
            $failures += $code === 0 ? 0 : 1;
            $this->release($name, $token, $code === 0, (int) ((microtime(true) - $t) * 1000), $message);
        }
        $this->release(self::SELF, $self, true, (int) ((microtime(true) - $started) * 1000), $failures === 0 ? 'ok' : "{$failures} tarefa(s) com falha");
        return $report;
    }

    /** @param array<string,mixed> $def */
    public function isDue(array $def, ?string $lastStartedAt): bool
    {
        $now = $this->clock->now();
        $last = $lastStartedAt !== null ? new DateTimeImmutable($lastStartedAt, new DateTimeZone('UTC')) : null;
        if (isset($def['every_minutes'])) {
            return $last === null || $now->getTimestamp() >= $last->getTimestamp() + max(1, (int) $def['every_minutes']) * 60 - self::SLACK_SECONDS;
        }
        if (isset($def['daily_at']) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $def['daily_at'], $m)) {
            $tz = new DateTimeZone($this->config->string('app.display_timezone', 'America/Sao_Paulo'));
            $local = $now->setTimezone($tz);
            if ($local->format('H:i') < $m[1] . ':' . $m[2]) {
                return false;
            }
            return $last === null || $last->setTimezone($tz)->format('Y-m-d') < $local->format('Y-m-d');
        }
        return false;
    }

    /** @return array<string,array<string,mixed>> */
    public function tasks(): array
    {
        $tasks = $this->config->get('jobs.schedule', []);
        return is_array($tasks) ? $tasks : [];
    }

    /** @return list<array<string,mixed>> heartbeat de cada tarefa (painel de saúde) */
    public function heartbeats(): array
    {
        return $this->db->all('SELECT name, locked_until, last_started_at, last_finished_at, last_status, last_duration_ms, last_message FROM scheduled_tasks ORDER BY name');
    }

    /** @return array<string,mixed>|null */
    private function state(string $name): ?array
    {
        $this->db->run('INSERT IGNORE INTO scheduled_tasks (name) VALUES (?)', [$name]);
        return $this->db->one('SELECT * FROM scheduled_tasks WHERE name = ?', [$name]);
    }

    /** @param array<string,mixed>|null $state */
    private function acquire(string $name, ?array $state): ?string
    {
        $token = bin2hex(random_bytes(16));
        $now = $this->clock->nowDb();
        $ok = $this->db->affected(
            'UPDATE scheduled_tasks SET lock_token = ?, locked_until = ?, last_started_at = ?, last_status = \'running\'
             WHERE name = ? AND (locked_until IS NULL OR locked_until < ?) AND last_started_at <=> ?',
            [$token, $this->clock->plusSecondsDb($this->config->int('jobs.task_lock_seconds', 900)), $now, $name, $now, $state['last_started_at'] ?? null]
        );
        return $ok === 1 ? $token : null;
    }

    private function release(string $name, string $token, bool $ok, int $durationMs, string $message): void
    {
        $this->db->run(
            'UPDATE scheduled_tasks SET lock_token = NULL, locked_until = NULL, last_finished_at = ?, last_status = ?, last_duration_ms = ?, last_message = ?
             WHERE name = ? AND lock_token = ?',
            [$this->clock->nowDb(), $ok ? 'ok' : 'failed', $durationMs, mb_substr($message, 0, 255), $name, $token]
        );
    }
}
