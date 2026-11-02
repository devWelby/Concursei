<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;

class UserRepository
{
    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    public function create(string $email, string $passwordHash, string $displayName, string $role = 'student', bool $verified = false): int
    {
        $now = $this->clock->nowDb();
        return $this->db->insert(
            'INSERT INTO users (email, email_verified_at, password_hash, display_name, role, status, password_changed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'active\', ?, ?, ?)',
            [mb_strtolower(trim($email)), $verified ? $now : null, $passwordHash, self::normalizeName($displayName), $role, $now, $now, $now]
        );
    }

    /** Nome de exibição em uma única linha, sem espaços repetidos. */
    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace("/\s+/u", " ", $name));
    }

    public function markEmailVerified(int $id): void
    {
        $this->db->run('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?', [$this->clock->nowDb(), $this->clock->nowDb(), $id]);
    }

    public function updatePasswordHash(int $id, string $hash, bool $changed = true): void
    {
        $now = $this->clock->nowDb();
        $this->db->run(
            'UPDATE users SET password_hash = ?, updated_at = ?' . ($changed ? ', password_changed_at = ?' : '') . ' WHERE id = ?',
            $changed ? [$hash, $now, $now, $id] : [$hash, $now, $id]
        );
    }

    public function updateProfile(int $id, string $displayName, string $timezone): void
    {
        $this->db->run('UPDATE users SET display_name = ?, timezone = ?, updated_at = ? WHERE id = ?', [self::normalizeName($displayName), $timezone, $this->clock->nowDb(), $id]);
    }

    public function updateEmail(int $id, string $email): void
    {
        $this->db->run('UPDATE users SET email = ?, email_verified_at = ?, updated_at = ? WHERE id = ?', [mb_strtolower($email), $this->clock->nowDb(), $this->clock->nowDb(), $id]);
    }

    public function recordLogin(int $id): void
    {
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [$this->clock->nowDb(), $id]);
    }

    public function setRole(int $id, string $role): void
    {
        $this->db->run('UPDATE users SET role = ?, updated_at = ? WHERE id = ?', [$role, $this->clock->nowDb(), $id]);
    }

    public function setMfa(int $id, ?string $secretEnc): void
    {
        $this->db->run(
            'UPDATE users SET mfa_secret_enc = ?, mfa_enabled_at = ?, mfa_last_timestep = NULL, updated_at = ? WHERE id = ?',
            [$secretEnc, $secretEnc !== null ? $this->clock->nowDb() : null, $this->clock->nowDb(), $id]
        );
    }

    /** Registra o passo TOTP usado; falso se já usado (anti-replay atômico). */
    public function claimMfaTimestep(int $id, int $step): bool
    {
        return $this->db->affected(
            'UPDATE users SET mfa_last_timestep = ? WHERE id = ? AND (mfa_last_timestep IS NULL OR mfa_last_timestep < ?)',
            [$step, $id, $step]
        ) === 1;
    }
}
