<?php

declare(strict_types=1);

namespace Concursei\Core\Security;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;

/**
 * Rate limit persistente e atômico (janela fixa) em tabela InnoDB.
 * O incremento é um único INSERT ... ON DUPLICATE KEY UPDATE, seguro sob concorrência.
 */
class RateLimiter
{
    public function __construct(private Database $db, private Clock $clock, private Crypto $crypto)
    {
    }

    /**
     * Registra uma tentativa e informa se está dentro do limite.
     * @return array{allowed:bool,remaining:int,retry_after:int}
     */
    public function hit(string $scope, string $identifier, int $max, int $windowSeconds): array
    {
        $key = $this->crypto->mac('ratelimit', $scope . '|' . $identifier);
        $now = $this->clock->now();
        $nowDb = $now->format('Y-m-d H:i:s');
        $windowStartCutoff = $now->modify('-' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');
        $expires = $now->modify('+' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');

        $this->db->run(
            'INSERT INTO rate_limits (bucket, scope, hits, window_start, expires_at) VALUES (:b, :s, 1, :now, :exp)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start <= :cut1, 1, hits + 1),
               expires_at = IF(window_start <= :cut2, :exp2, expires_at),
               window_start = IF(window_start <= :cut3, :now2, window_start)',
            ['b' => $key, 's' => substr($scope, 0, 64), 'now' => $nowDb, 'exp' => $expires, 'cut1' => $windowStartCutoff,
                'cut2' => $windowStartCutoff, 'exp2' => $expires, 'cut3' => $windowStartCutoff, 'now2' => $nowDb]
        );
        $row = $this->db->one('SELECT hits, window_start FROM rate_limits WHERE bucket = ?', [$key]);
        $hits = (int) ($row['hits'] ?? 1);
        $start = new \DateTimeImmutable((string) ($row['window_start'] ?? $nowDb), new \DateTimeZone('UTC'));
        $retry = max(1, $windowSeconds - ($now->getTimestamp() - $start->getTimestamp()));
        return ['allowed' => $hits <= $max, 'remaining' => max(0, $max - $hits), 'retry_after' => $retry];
    }

    /** Verifica sem incrementar. */
    public function tooMany(string $scope, string $identifier, int $max, int $windowSeconds): bool
    {
        $key = $this->crypto->mac('ratelimit', $scope . '|' . $identifier);
        $cut = $this->clock->now()->modify('-' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');
        $hits = $this->db->scalar('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start > ?', [$key, $cut]);
        return $hits !== null && (int) $hits >= $max;
    }

    public function clear(string $scope, string $identifier): void
    {
        $this->db->run('DELETE FROM rate_limits WHERE bucket = ?', [$this->crypto->mac('ratelimit', $scope . '|' . $identifier)]);
    }

    public function purgeExpired(): int
    {
        return $this->db->affected('DELETE FROM rate_limits WHERE expires_at < ?', [$this->clock->nowDb()]);
    }
}
