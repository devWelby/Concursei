<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Core\Security\RateLimiter;
use Concursei\Core\Session\DatabaseSessionHandler;
use Concursei\Modules\Mail\Outbox;
use Concursei\Shared\Audit\AuditLog;
use Concursei\Shared\Auth\AuthUser;
use Concursei\Shared\Privacy\ConsentService;

/**
 * Regras de conta. Respostas públicas são genéricas para não revelar se um
 * e-mail está cadastrado. E-mails são gravados no outbox na mesma transação
 * do token e enviados após o commit (melhor esforço) ou pelo Cron.
 */
class AuthService
{
    public const VERIFY_TTL = 86400;
    public const RESET_TTL = 3600;
    public const EMAIL_CHANGE_TTL = 86400;

    public function __construct(
        private Database $db,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private TokenService $tokens,
        private Outbox $outbox,
        private RateLimiter $limiter,
        private AuditLog $audit,
        private ConsentService $consents,
        private DatabaseSessionHandler $sessions,
        private Config $config,
    ) {
    }

    public function mailAvailable(): bool
    {
        return $this->outbox->isAvailable();
    }

    private function url(string $path, string $token): string
    {
        return rtrim($this->config->string('app.url'), '/') . $path . '?token=' . rawurlencode($token);
    }

    /** Limita e-mails para o mesmo endereço (evita usar o site para spam). */
    private function mailQuotaOk(string $email): bool
    {
        return $this->limiter->hit('mail_to', mb_strtolower($email), 3, 3600)['allowed'];
    }

    /**
     * Cadastro. Sempre termina com a mesma resposta pública.
     * @param array{display_name:string,email:string,password:string,marketing:bool} $data
     */
    public function register(array $data, string $ip): void
    {
        $email = mb_strtolower($data['email']);
        $existing = $this->users->findByEmail($email);
        $outboxId = null;
        if ($existing !== null) {
            // Conta já existe: avisa o titular, sem revelar nada a quem tentou.
            if ($existing['status'] === 'active' && $this->mailQuotaOk($email)) {
                $outboxId = $this->outbox->enqueue($email, 'auth_already_registered', [
                    'name' => $existing['display_name'],
                    'loginUrl' => rtrim($this->config->string('app.url'), '/') . '/entrar',
                    'resetUrl' => rtrim($this->config->string('app.url'), '/') . '/recuperar-senha',
                ], 'already:' . $existing['id'] . ':' . bin2hex(random_bytes(6)), (int) $existing['id']);
            }
            $this->hasher->burn($data['password']);
            $this->outbox->sendNow($outboxId);
            return;
        }
        $outboxId = $this->db->transaction(function () use ($data, $email, $ip): ?int {
            $userId = $this->users->create($email, $this->hasher->hash($data['password']), $data['display_name']);
            $terms = $this->config->string('legal.terms_version');
            $privacy = $this->config->string('legal.privacy_version');
            $this->consents->record($userId, null, 'terms', $terms, true, 'signup', $ip);
            $this->consents->record($userId, null, 'privacy', $privacy, true, 'signup', $ip);
            $this->consents->record($userId, null, 'age_18', $terms, true, 'signup', $ip);
            $this->consents->record($userId, null, 'marketing_email', $privacy, $data['marketing'], 'signup', $ip);
            $token = $this->tokens->issue($userId, 'email_verify', self::VERIFY_TTL);
            $this->audit->record($userId, 'auth.registered', 'user', $userId, [], $ip);
            $this->mailQuotaOk($email);
            return $this->outbox->enqueue($email, 'auth_verify', [
                'name' => $data['display_name'], 'url' => $this->url('/confirmar-email', $token),
            ], 'verify:' . $userId . ':' . substr(hash('sha256', $token), 0, 16), $userId);
        });
        $this->outbox->sendNow($outboxId);
    }

    public function resendVerification(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || $user['email_verified_at'] !== null || $user['status'] !== 'active' || !$this->mailQuotaOk($email)) {
            return;
        }
        $id = $this->db->transaction(function () use ($user): ?int {
            $token = $this->tokens->issue((int) $user['id'], 'email_verify', self::VERIFY_TTL);
            return $this->outbox->enqueue((string) $user['email'], 'auth_verify', [
                'name' => $user['display_name'], 'url' => $this->url('/confirmar-email', $token),
            ], 'verify:' . $user['id'] . ':' . substr(hash('sha256', $token), 0, 16), (int) $user['id']);
        });
        $this->outbox->sendNow($id);
    }

    /** @return int|null id do usuário confirmado */
    public function verifyEmail(string $token, string $ip): ?int
    {
        $row = $this->tokens->consume($token, 'email_verify');
        if ($row === null) {
            return null;
        }
        $userId = (int) $row['user_id'];
        $this->users->markEmailVerified($userId);
        $this->audit->record($userId, 'auth.email_verified', 'user', $userId, [], $ip);
        return $userId;
    }

    /**
     * @return array{status:'ok'|'mfa'|'invalid'|'unverified'|'throttled', user?:AuthUser, retry_after?:int}
     */
    public function attemptLogin(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $perAccount = $this->limiter->hit('login_account', $email, 10, 900);
        if (!$perAccount['allowed']) {
            return ['status' => 'throttled', 'retry_after' => $perAccount['retry_after']];
        }
        $row = $this->users->findByEmail($email);
        if ($row === null) {
            $this->hasher->burn($password);
            $this->audit->record(null, 'auth.login_failed', null, null, ['reason' => 'credentials'], $ip);
            return ['status' => 'invalid'];
        }
        if (!$this->hasher->verify($password, (string) $row['password_hash']) || $row['status'] !== 'active') {
            $this->audit->record(null, 'auth.login_failed', 'user', (int) $row['id'], ['reason' => 'credentials'], $ip);
            return ['status' => 'invalid'];
        }
        if ($this->hasher->needsRehash((string) $row['password_hash'])) {
            $this->users->updatePasswordHash((int) $row['id'], $this->hasher->hash($password), false);
        }
        if ($row['email_verified_at'] === null) {
            return ['status' => 'unverified'];
        }
        $this->limiter->clear('login_account', $email);
        $user = AuthUser::fromRow($row);
        if ($user->mfaEnabled) {
            return ['status' => 'mfa', 'user' => $user];
        }
        $this->completeLogin($user->id, false, $ip);
        return ['status' => 'ok', 'user' => $user];
    }

    public function completeLogin(int $userId, bool $withMfa, string $ip): void
    {
        $this->users->recordLogin($userId);
        $this->audit->record($userId, 'auth.login', 'user', $userId, ['mfa' => $withMfa], $ip);
    }

    public function requestPasswordReset(string $email, string $ip): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || $user['status'] !== 'active' || !$this->mailQuotaOk((string) $user['email'])) {
            return;
        }
        $id = $this->db->transaction(function () use ($user, $ip): ?int {
            $token = $this->tokens->issue((int) $user['id'], 'password_reset', self::RESET_TTL);
            $this->audit->record(null, 'auth.reset_requested', 'user', (int) $user['id'], [], $ip);
            return $this->outbox->enqueue((string) $user['email'], 'auth_reset', [
                'name' => $user['display_name'], 'url' => $this->url('/redefinir-senha', $token), 'minutes' => intdiv(self::RESET_TTL, 60),
            ], 'reset:' . $user['id'] . ':' . substr(hash('sha256', $token), 0, 16), (int) $user['id']);
        });
        $this->outbox->sendNow($id);
    }

    public function resetTokenValid(string $token): bool
    {
        return $this->tokens->peek($token, 'password_reset') !== null;
    }

    /** Redefine a senha, encerra TODAS as sessões e notifica o titular. */
    public function resetPassword(string $token, string $newPassword, string $ip): bool
    {
        $row = $this->tokens->consume($token, 'password_reset');
        if ($row === null) {
            return false;
        }
        $userId = (int) $row['user_id'];
        $id = $this->db->transaction(function () use ($userId, $newPassword, $ip): ?int {
            $this->users->updatePasswordHash($userId, $this->hasher->hash($newPassword));
            $this->tokens->invalidate($userId, 'password_reset');
            $this->sessions->destroyForUser($userId);
            $this->audit->record($userId, 'auth.password_reset', 'user', $userId, [], $ip);
            return $this->notifyPasswordChanged($userId);
        });
        $this->outbox->sendNow($id);
        return true;
    }

    /** Troca de senha autenticada: exige a senha atual; encerra as demais sessões. */
    public function changePassword(AuthUser $user, string $current, string $new, ?string $keepSessionId, string $ip): bool
    {
        $row = $this->users->findById($user->id);
        if ($row === null || !$this->hasher->verify($current, (string) $row['password_hash'])) {
            $this->audit->record($user->id, 'auth.reauth_failed', 'user', $user->id, ['action' => 'password_change'], $ip);
            return false;
        }
        $id = $this->db->transaction(function () use ($user, $new, $keepSessionId, $ip): ?int {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($new));
            $this->tokens->invalidate($user->id, 'password_reset');
            $this->sessions->destroyForUser($user->id, $keepSessionId);
            $this->audit->record($user->id, 'auth.password_changed', 'user', $user->id, [], $ip);
            return $this->notifyPasswordChanged($user->id);
        });
        $this->outbox->sendNow($id);
        return true;
    }

    private function notifyPasswordChanged(int $userId): ?int
    {
        $row = $this->users->findById($userId);
        if ($row === null) {
            return null;
        }
        return $this->outbox->enqueue((string) $row['email'], 'auth_password_changed', [
            'name' => $row['display_name'], 'resetUrl' => rtrim($this->config->string('app.url'), '/') . '/recuperar-senha',
        ], 'pwchanged:' . $userId . ':' . bin2hex(random_bytes(6)), $userId);
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $row = $this->users->findById($userId);
        return $row !== null && $this->hasher->verify($password, (string) $row['password_hash']);
    }

    /** @return 'sent'|'bad_password'|'same'|'taken' resposta 'taken' só é tratada como 'sent' na UI */
    public function requestEmailChange(AuthUser $user, string $password, string $newEmail, string $ip): string
    {
        if (!$this->verifyPassword($user->id, $password)) {
            $this->audit->record($user->id, 'auth.reauth_failed', 'user', $user->id, ['action' => 'email_change'], $ip);
            return 'bad_password';
        }
        $newEmail = mb_strtolower($newEmail);
        if ($newEmail === $user->email) {
            return 'same';
        }
        if ($this->users->findByEmail($newEmail) !== null || !$this->mailQuotaOk($newEmail)) {
            return 'taken';
        }
        $id = $this->db->transaction(function () use ($user, $newEmail, $ip): ?int {
            $token = $this->tokens->issue($user->id, 'email_change', self::EMAIL_CHANGE_TTL, ['email' => $newEmail]);
            $this->audit->record($user->id, 'auth.email_change_requested', 'user', $user->id, [], $ip);
            return $this->outbox->enqueue($newEmail, 'auth_email_change', [
                'name' => $user->displayName, 'url' => $this->url('/conta/email/confirmar', $token),
            ], 'emailchange:' . $user->id . ':' . substr(hash('sha256', $token), 0, 16), $user->id);
        });
        $this->outbox->sendNow($id);
        return 'sent';
    }

    public function confirmEmailChange(string $token, string $ip): bool
    {
        $row = $this->tokens->consume($token, 'email_change');
        $newEmail = is_array($row['payload'] ?? null) ? ($row['payload']['email'] ?? null) : null;
        if ($row === null || !is_string($newEmail) || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $userId = (int) $row['user_id'];
        $old = $this->users->findById($userId);
        if ($old === null) {
            return false;
        }
        try {
            $id = $this->db->transaction(function () use ($userId, $newEmail, $old, $ip): ?int {
                $this->users->updateEmail($userId, $newEmail);
                $this->audit->record($userId, 'auth.email_changed', 'user', $userId, [], $ip);
                return $this->outbox->enqueue((string) $old['email'], 'auth_email_changed', [
                    'name' => $old['display_name'], 'resetUrl' => rtrim($this->config->string('app.url'), '/') . '/recuperar-senha',
                ], 'emailchanged:' . $userId . ':' . bin2hex(random_bytes(6)), $userId);
            });
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                return false; // outro cadastro usou o e-mail nesse intervalo
            }
            throw $e;
        }
        $this->outbox->sendNow($id);
        return true;
    }

    public function notifyMfaChanged(int $userId, bool $enabled): void
    {
        $row = $this->users->findById($userId);
        if ($row === null) {
            return;
        }
        $id = $this->outbox->enqueue((string) $row['email'], 'auth_mfa_changed', [
            'name' => $row['display_name'], 'enabled' => $enabled,
        ], 'mfa:' . $userId . ':' . bin2hex(random_bytes(6)), $userId);
        $this->outbox->sendNow($id);
    }
}
