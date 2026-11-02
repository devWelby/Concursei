<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;
use Concursei\Core\Security\Crypto;
use Concursei\Core\Security\RateLimiter;
use OTPHP\TOTP;

/**
 * MFA TOTP (RFC 6238) via OTPHP: semente cifrada em repouso (AES-256-GCM com AAD
 * do usuário), tolerância de ±1 passo, anti-replay por passo usado e códigos de
 * recuperação de uso único (HMAC no banco; exibidos uma única vez).
 */
class MfaService
{
    private const PERIOD = 30;
    private const RECOVERY_CODES = 10;
    /** Falhas por CONTA (independe de IP): 10 a cada 15 min ⇒ adivinhar TOTP fica inviável. */
    public const MAX_FAILURES = 10;
    public const FAILURE_WINDOW = 900;

    public function __construct(private Database $db, private Clock $clock, private Crypto $crypto, private UserRepository $users, private RateLimiter $limiter)
    {
    }

    public function lockedOut(int $userId): bool
    {
        return $this->limiter->tooMany('mfa_account', (string) $userId, self::MAX_FAILURES, self::FAILURE_WINDOW);
    }

    /** 160 bits (RFC 4226 §4): 32 caracteres base32 — viável de digitar sem QR code. */
    public const SECRET_BYTES = 20;

    public function newSecret(): string
    {
        return TOTP::generate($this->clock, self::SECRET_BYTES)->getSecret();
    }

    public function provisioningUri(string $secret, string $label, string $issuer): string
    {
        $totp = TOTP::createFromSecret($secret, $this->clock);
        $totp->setLabel($label);
        $totp->setIssuer($issuer);
        return $totp->getProvisioningUri();
    }

    /** Passo TOTP correspondente ao código (±1 passo) ou null. */
    public function matchStep(string $secret, string $code): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $totp = TOTP::createFromSecret($secret, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        foreach ([0, -1, 1] as $offset) {
            $ts = $now + $offset * self::PERIOD;
            if (hash_equals($totp->at($ts), $code)) {
                return intdiv($ts, self::PERIOD);
            }
        }
        return null;
    }

    /**
     * Confirma a configuração com um código válido e ativa o MFA.
     * @return list<string>|null códigos de recuperação em claro (mostrar uma vez) ou null se o código for inválido
     */
    public function enable(int $userId, string $secret, string $code): ?array
    {
        $step = $this->matchStep($secret, $code);
        if ($step === null) {
            return null;
        }
        return $this->db->transaction(function () use ($userId, $secret, $step): array {
            $this->users->setMfa($userId, $this->crypto->encrypt($secret, 'mfa:' . $userId));
            $this->users->claimMfaTimestep($userId, $step);
            return $this->regenerateRecoveryCodes($userId);
        });
    }

    public function disable(int $userId): void
    {
        $this->db->transaction(function () use ($userId): void {
            $this->users->setMfa($userId, null);
            $this->db->run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        });
    }

    /** Verifica código TOTP (com anti-replay) ou código de recuperação. */
    public function verifyForUser(int $userId, string $code): bool
    {
        $row = $this->users->findById($userId);
        if ($row === null || $row['mfa_secret_enc'] === null || $this->lockedOut($userId)) {
            return false;
        }
        $secret = $this->crypto->decrypt((string) $row['mfa_secret_enc'], 'mfa:' . $userId);
        $step = $this->matchStep($secret, $code);
        $ok = $step !== null ? $this->users->claimMfaTimestep($userId, $step) : $this->useRecoveryCode($userId, $code);
        if ($ok) {
            $this->limiter->clear('mfa_account', (string) $userId);
        } else {
            $this->limiter->hit('mfa_account', (string) $userId, self::MAX_FAILURES, self::FAILURE_WINDOW);
        }
        return $ok;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(int $userId): array
    {
        return $this->db->transaction(fn (): array => $this->replaceRecoveryCodes($userId));
    }

    /** @return list<string> */
    private function replaceRecoveryCodes(int $userId): array
    {
        $this->db->run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $codes[] = $code;
            $this->db->run('INSERT INTO mfa_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)', [
                $userId, $this->crypto->mac('mfa-recovery', $code), $this->clock->nowDb(),
            ]);
        }
        return $codes;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    private function useRecoveryCode(int $userId, string $code): bool
    {
        $normalized = strtolower(trim($code));
        if (!preg_match('/^[a-z0-9]{5}-[a-z0-9]{5}$/', $normalized)) {
            return false;
        }
        return $this->db->affected(
            'UPDATE mfa_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$this->clock->nowDb(), $userId, $this->crypto->mac('mfa-recovery', $normalized)]
        ) === 1;
    }
}
