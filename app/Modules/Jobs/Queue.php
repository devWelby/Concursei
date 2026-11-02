<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Core\Logging\Logger;
use InvalidArgumentException;

/**
 * Fila SQL (D-016).
 * - Reserva atômica por UPDATE ... ORDER BY ... LIMIT 1 com token aleatório (sem SKIP LOCKED).
 * - Lease com expiração: job de worker interrompido volta a ser reservável quando locked_until vence.
 * - Toda finalização exige o token de posse: worker antigo não sobrescreve o resultado do novo.
 * - unique_key deduplica jobs ativos; é limpo no estado final para permitir nova execução depois.
 */
class Queue
{
    public const ACTIVE = ['pending', 'running', 'retry_scheduled'];

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private JobRegistry $registry,
        private Logger $logger,
    ) {
    }

    /**
     * Enfileira um job. Retorna o id criado ou, se já existe job ativo com a mesma chave, o id dele.
     * @param array<string,mixed> $payload
     */
    public function dispatch(string $type, array $payload = [], ?string $uniqueKey = null, ?int $ownerUserId = null, int $delaySeconds = 0, int $maxAttempts = 5, int $priority = 100): int
    {
        if (!$this->registry->has($type)) {
            throw new InvalidArgumentException("Tipo de job não registrado: {$type}");
        }
        self::assertNoSecrets($payload);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > $this->config->int('jobs.max_payload_bytes', 16384)) {
            throw new InvalidArgumentException('Payload do job excede o limite.');
        }
        $now = $this->clock->nowDb();
        try {
            return $this->db->insert(
                'INSERT INTO jobs (type, payload, status, priority, attempts, max_attempts, unique_key, owner_user_id, available_at, created_at, updated_at)
                 VALUES (?, ?, \'pending\', ?, 0, ?, ?, ?, ?, ?, ?)',
                [$type, $json, max(0, min(255, $priority)), max(1, min(20, $maxAttempts)), $uniqueKey !== null ? substr($uniqueKey, 0, 190) : null,
                    $ownerUserId, $this->clock->plusSecondsDb(max(0, $delaySeconds)), $now, $now]
            );
        } catch (\PDOException $e) {
            if ($uniqueKey !== null && Database::isDuplicateKey($e)) {
                return (int) $this->db->scalar('SELECT id FROM jobs WHERE unique_key = ?', [substr($uniqueKey, 0, 190)]);
            }
            throw $e;
        }
    }

    /** Reserva o próximo job disponível (ou com lease vencido). Nunca dentro de transação aberta. */
    public function claim(?int $leaseSeconds = null): ?Job
    {
        $token = bin2hex(random_bytes(16));
        $now = $this->clock->nowDb();
        $lease = $leaseSeconds ?? $this->config->int('jobs.lease_seconds', 300);
        $claimed = $this->db->affected(
            'UPDATE jobs SET status = \'running\', lock_token = ?, locked_until = ?, attempts = attempts + 1, started_at = ?, updated_at = ?
             WHERE (status IN (\'pending\', \'retry_scheduled\') AND available_at <= ?) OR (status = \'running\' AND locked_until < ?)
             ORDER BY priority, available_at, id LIMIT 1',
            [$token, $this->clock->plusSecondsDb($lease), $now, $now, $now, $now]
        );
        if ($claimed === 0) {
            return null;
        }
        $row = $this->db->one('SELECT * FROM jobs WHERE lock_token = ? AND status = \'running\'', [$token]);
        if ($row === null) {
            return null;
        }
        $job = Job::fromRow($row);
        if ($job->attempts > $job->maxAttempts) {
            // Reserva de lease vencido após a última tentativa: o worker anterior morreu no meio.
            $this->fail($job, 'Tentativas esgotadas (execução anterior interrompida).');
            return $this->claim($leaseSeconds);
        }
        return $job;
    }

    /** @param array<string,scalar|null>|null $result */
    public function complete(Job $job, ?array $result = null): bool
    {
        $json = $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
        if ($json !== null && strlen($json) > 4000) {
            $json = '{"truncated":true}';
        }
        return $this->finish($job, 'completed', null, $json);
    }

    public function fail(Job $job, string $error): bool
    {
        $ok = $this->finish($job, 'failed', $error, null);
        $this->logger->warning('Job falhou definitivamente', ['job_id' => $job->id, 'type' => $job->type, 'attempts' => $job->attempts]);
        return $ok;
    }

    /** Reagenda com backoff exponencial + jitter; esgotadas as tentativas, falha definitiva. */
    public function retryOrFail(Job $job, string $error, ?int $afterSeconds = null): string
    {
        if ($job->attempts >= $job->maxAttempts) {
            $this->fail($job, $error);
            return 'failed';
        }
        $delay = $afterSeconds !== null
            ? max(5, min(86400, $afterSeconds))
            : min(3600, 30 * (2 ** max(0, $job->attempts - 1))) + random_int(0, 15);
        $this->db->run(
            'UPDATE jobs SET status = \'retry_scheduled\', available_at = ?, lock_token = NULL, locked_until = NULL, last_error = ?, updated_at = ?
             WHERE id = ? AND lock_token = ? AND status = \'running\'',
            [$this->clock->plusSecondsDb($delay), self::cleanError($error), $this->clock->nowDb(), $job->id, $job->lockToken]
        );
        return 'retry';
    }

    /** Estende o lease de um job longo (handler chama entre etapas). */
    public function heartbeat(Job $job, ?int $leaseSeconds = null): bool
    {
        return $this->db->affected(
            'UPDATE jobs SET locked_until = ?, updated_at = ? WHERE id = ? AND lock_token = ? AND status = \'running\'',
            [$this->clock->plusSecondsDb($leaseSeconds ?? $this->config->int('jobs.lease_seconds', 300)), $this->clock->nowDb(), $job->id, $job->lockToken]
        ) === 1;
    }

    /** Reprocessamento administrativo de job falho ou cancelado (dead letter). */
    public function requeue(int $id): bool
    {
        $now = $this->clock->nowDb();
        return $this->db->affected(
            'UPDATE jobs SET status = \'pending\', attempts = 0, available_at = ?, last_error = NULL, finished_at = NULL, updated_at = ?
             WHERE id = ? AND status IN (\'failed\', \'cancelled\')',
            [$now, $now, $id]
        ) === 1;
    }

    /** Cancela job que ainda não está em execução. */
    public function cancel(int $id): bool
    {
        $now = $this->clock->nowDb();
        return $this->db->affected(
            'UPDATE jobs SET status = \'cancelled\', unique_key = NULL, finished_at = ?, updated_at = ?
             WHERE id = ? AND status IN (\'pending\', \'retry_scheduled\')',
            [$now, $now, $id]
        ) === 1;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM jobs WHERE id = ?', [$id]);
    }

    /** @return array{counts:array<string,int>,stuck:int,oldest_pending:?string} */
    public function stats(): array
    {
        $counts = array_fill_keys(['pending', 'running', 'retry_scheduled', 'completed', 'failed', 'cancelled'], 0);
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        $now = $this->clock->nowDb();
        return [
            'counts' => $counts,
            'stuck' => (int) $this->db->scalar('SELECT COUNT(*) FROM jobs WHERE status = \'running\' AND locked_until < ?', [$now]),
            'oldest_pending' => $this->db->scalar('SELECT MIN(available_at) FROM jobs WHERE status IN (\'pending\', \'retry_scheduled\') AND available_at <= ?', [$now]) ?: null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recent(?string $status, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        if ($status !== null) {
            return $this->db->all('SELECT id, type, status, attempts, max_attempts, last_error, available_at, finished_at, updated_at FROM jobs WHERE status = ? ORDER BY updated_at DESC, id DESC LIMIT ' . $limit, [$status]);
        }
        return $this->db->all('SELECT id, type, status, attempts, max_attempts, last_error, available_at, finished_at, updated_at FROM jobs ORDER BY updated_at DESC, id DESC LIMIT ' . $limit);
    }

    /** Expurgo por retenção (Cron). */
    public function purge(): int
    {
        $completed = $this->clock->plusSecondsDb(-86400 * $this->config->int('jobs.keep_completed_days', 7));
        $failed = $this->clock->plusSecondsDb(-86400 * $this->config->int('jobs.keep_failed_days', 30));
        return $this->db->affected('DELETE FROM jobs WHERE status = \'completed\' AND finished_at < ?', [$completed])
            + $this->db->affected('DELETE FROM jobs WHERE status IN (\'failed\', \'cancelled\') AND finished_at < ?', [$failed]);
    }

    private function finish(Job $job, string $status, ?string $error, ?string $result): bool
    {
        $now = $this->clock->nowDb();
        return $this->db->affected(
            'UPDATE jobs SET status = ?, unique_key = NULL, lock_token = NULL, locked_until = NULL, finished_at = ?, updated_at = ?, last_error = ?, result = ?
             WHERE id = ? AND lock_token = ? AND status = \'running\'',
            [$status, $now, $now, $error !== null ? self::cleanError($error) : null, $result, $job->id, $job->lockToken]
        ) === 1;
    }

    private static function cleanError(string $error): string
    {
        return mb_substr(Logger::redactString($error), 0, 500);
    }

    /** Payload não carrega segredos: chaves sensíveis são recusadas (referência por configuração). */
    private static function assertNoSecrets(array $payload, int $depth = 0): void
    {
        if ($depth > 4) {
            throw new InvalidArgumentException('Payload do job aninhado demais.');
        }
        foreach ($payload as $key => $value) {
            if (is_string($key) && Logger::isSensitiveKey($key)) {
                throw new InvalidArgumentException("Payload de job não pode conter segredo ({$key}).");
            }
            if (is_array($value)) {
                self::assertNoSecrets($value, $depth + 1);
            }
        }
    }
}
