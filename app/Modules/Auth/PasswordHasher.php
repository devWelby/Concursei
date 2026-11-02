<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

/**
 * Hash de senha com algoritmo nativo do PHP (sem criptografia caseira).
 * - Argon2id (parâmetros mínimos recomendados pela OWASP: m=19 MiB, t=2, p=1).
 * - Fallback bcrypt custo 12 com pré-hash HMAC-SHA384 em base64 (64 chars),
 *   evitando o truncamento silencioso do bcrypt acima de 72 bytes.
 * Rehash automático no login quando o algoritmo/parâmetros mudarem.
 */
class PasswordHasher
{
    private const PREHASH_KEY = 'concursei-bcrypt-prehash-v1';
    private ?string $dummy = null;

    public function __construct(private bool $preferArgon = true)
    {
    }

    private function useArgon(): bool
    {
        return $this->preferArgon && defined('PASSWORD_ARGON2ID');
    }

    public function hash(string $password): string
    {
        if ($this->useArgon()) {
            return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        }
        return password_hash(self::prehash($password), PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function verify(string $password, string $hash): bool
    {
        if (str_starts_with($hash, '$2y$')) {
            return password_verify(self::prehash($password), $hash);
        }
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        if ($this->useArgon()) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Executa verificação equivalente quando o usuário não existe (reduz diferença de tempo). */
    public function burn(string $password): void
    {
        $this->dummy ??= $this->hash(bin2hex(random_bytes(16)));
        $this->verify($password, $this->dummy);
    }

    private static function prehash(string $password): string
    {
        return base64_encode(hash_hmac('sha384', $password, self::PREHASH_KEY, true));
    }
}
