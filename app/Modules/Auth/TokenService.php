<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;
use Concursei\Core\Security\Crypto;

/**
 * Tokens de uso único (confirmação de e-mail, redefinição de senha, troca de e-mail):
 * 256 bits aleatórios, somente SHA-256 no banco, finalidade, expiração e consumo
 * transacional (dois cliques simultâneos não consomem duas vezes).
 */
class TokenService
{
    public const PURPOSES = ['email_verify', 'password_reset', 'email_change'];

    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** @param array<string,mixed>|null $payload */
    public function issue(int $userId, string $purpose, int $ttlSeconds, ?array $payload = null): string
    {
        $this->assertPurpose($purpose);
        $this->invalidate($userId, $purpose);
        $token = Crypto::randomToken(32);
        $this->db->run(
            'INSERT INTO auth_tokens (user_id, purpose, token_hash, payload, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $purpose, Crypto::tokenHash($token), $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                $this->clock->plusSecondsDb($ttlSeconds), $this->clock->nowDb()]
        );
        return $token;
    }

    /** Valida sem consumir (para exibir o formulário de confirmação). @return array<string,mixed>|null */
    public function peek(string $token, string $purpose): ?array
    {
        if (!self::wellFormed($token)) {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [Crypto::tokenHash($token), $purpose, $this->clock->nowDb()]
        );
    }

    /** Consome atomicamente. @return array<string,mixed>|null linha do token (com payload decodificado) */
    public function consume(string $token, string $purpose): ?array
    {
        if (!self::wellFormed($token)) {
            return null;
        }
        return $this->db->transaction(function (Database $db) use ($token, $purpose): ?array {
            $row = $db->one(
                'SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = ? FOR UPDATE',
                [Crypto::tokenHash($token), $purpose]
            );
            if ($row === null || $row['used_at'] !== null || (string) $row['expires_at'] <= $this->clock->nowDb()) {
                return null;
            }
            $db->run('UPDATE auth_tokens SET used_at = ? WHERE id = ?', [$this->clock->nowDb(), $row['id']]);
            $row['payload'] = $row['payload'] !== null ? json_decode((string) $row['payload'], true) : null;
            return $row;
        });
    }

    public function invalidate(int $userId, ?string $purpose = null): void
    {
        $sql = 'UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL';
        $params = [$this->clock->nowDb(), $userId];
        if ($purpose !== null) {
            $sql .= ' AND purpose = ?';
            $params[] = $purpose;
        }
        $this->db->run($sql, $params);
    }

    public function purgeExpired(): int
    {
        return $this->db->affected('DELETE FROM auth_tokens WHERE expires_at < ?', [$this->clock->plusSecondsDb(-86400)]);
    }

    private static function wellFormed(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Finalidade de token inválida.');
        }
    }
}
