<?php

declare(strict_types=1);

namespace Concursei\Shared\Auth;

/**
 * Usuário autenticado (somente campos necessários; nunca o hash de senha ou a semente MFA).
 */
final class AuthUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $role,
        public readonly string $status,
        public readonly bool $emailVerified,
        public readonly bool $mfaEnabled,
        public readonly string $timezone,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['role'],
            (string) $row['status'],
            $row['email_verified_at'] !== null,
            $row['mfa_enabled_at'] !== null,
            (string) $row['timezone'],
        );
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['editor', 'admin'], true);
    }

    public function can(string $permission): bool
    {
        return Permissions::allows($this->role, $permission);
    }

    /** Dados seguros para templates. @return array<string,mixed> */
    public function toView(): array
    {
        return ['id' => $this->id, 'display_name' => $this->displayName, 'role' => $this->role, 'mfa_enabled' => $this->mfaEnabled];
    }
}
