<?php

declare(strict_types=1);

namespace Concursei\Core\Session;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sessões em banco: o ID de sessão nunca é armazenado em claro (apenas SHA-256),
 * cada sessão é vinculada ao usuário para permitir logout global (troca de senha,
 * exclusão de conta), e um lock nomeado por sessão serializa requisições
 * concorrentes como o handler de arquivos do PHP.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    private ?int $userId = null;
    private ?string $lockName = null;

    public function __construct(private Database $db, private Clock $clock, private int $lifetimeSeconds)
    {
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    private static function hashId(string $id): string
    {
        return hash('sha256', $id, true);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        if ($this->lockName !== null) {
            $this->db->scalar('SELECT RELEASE_LOCK(?)', [$this->lockName]);
            $this->lockName = null;
        }
        return true;
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function validateId(string $id): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            return false;
        }
        return $this->db->scalar('SELECT 1 FROM sessions WHERE id_hash = ? AND expires_at > ?', [self::hashId($id), $this->clock->nowDb()]) !== null;
    }

    public function read(string $id): string|false
    {
        $this->lockName = 'sess_' . substr(bin2hex(self::hashId($id)), 0, 40);
        $this->db->scalar('SELECT GET_LOCK(?, 5)', [$this->lockName]);
        $row = $this->db->one('SELECT data FROM sessions WHERE id_hash = ? AND expires_at > ?', [self::hashId($id), $this->clock->nowDb()]);
        return $row === null ? '' : (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        $now = $this->clock->nowDb();
        $exp = $this->clock->plusSecondsDb($this->lifetimeSeconds);
        $this->db->run(
            'INSERT INTO sessions (id_hash, user_id, data, created_at, last_activity_at, expires_at)
             VALUES (:id, :uid, :data, :now, :now2, :exp)
             ON DUPLICATE KEY UPDATE user_id = :uid2, data = :data2, last_activity_at = :now3, expires_at = :exp2',
            ['id' => self::hashId($id), 'uid' => $this->userId, 'data' => $data, 'now' => $now, 'now2' => $now, 'exp' => $exp,
                'uid2' => $this->userId, 'data2' => $data, 'now3' => $now, 'exp2' => $exp]
        );
        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->db->run('UPDATE sessions SET last_activity_at = ?, expires_at = ? WHERE id_hash = ?', [
            $this->clock->nowDb(), $this->clock->plusSecondsDb($this->lifetimeSeconds), self::hashId($id),
        ]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->run('DELETE FROM sessions WHERE id_hash = ?', [self::hashId($id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->db->affected('DELETE FROM sessions WHERE expires_at < ?', [$this->clock->nowDb()]);
    }

    /** Encerra todas as sessões de um usuário (opcionalmente preservando a atual). */
    public function destroyForUser(int $userId, ?string $exceptId = null): int
    {
        if ($exceptId !== null) {
            return $this->db->affected('DELETE FROM sessions WHERE user_id = ? AND id_hash <> ?', [$userId, self::hashId($exceptId)]);
        }
        return $this->db->affected('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }
}
