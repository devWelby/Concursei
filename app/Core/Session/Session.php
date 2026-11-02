<?php

declare(strict_types=1);

namespace Concursei\Core\Session;

use Concursei\Core\Clock;

/**
 * Sessão PHP endurecida: modo estrito, cookies Secure/HttpOnly/SameSite,
 * prefixo __Host- em HTTPS, expiração ociosa e absoluta, regeneração de ID em
 * login/elevação, CSRF sincronizado e mensagens flash.
 */
class Session
{
    private bool $started = false;

    public function __construct(
        private DatabaseSessionHandler $handler,
        private Clock $clock,
        private bool $secure,
        private int $idleSeconds,
        private int $absoluteSeconds,
    ) {
    }

    public function cookieName(): string
    {
        return $this->secure ? '__Host-concursei' : 'concursei_session';
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.lazy_write', '1');
        ini_set('session.gc_probability', '0'); // limpeza via Cron (session:gc)
        ini_set('session.serialize_handler', 'php_serialize');
        session_name($this->cookieName());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_set_save_handler($this->handler, false);
        if (PHP_SAPI === 'cli') {
            // Em testes/CLI não há cabeçalhos; usa armazenamento em memória.
            $_SESSION = $_SESSION ?? [];
            $this->started = true;
            $this->enforceTimeouts();
            return;
        }
        session_start();
        $this->started = true;
        $this->enforceTimeouts();
    }

    private function enforceTimeouts(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $created = (int) ($_SESSION['_created'] ?? 0);
        $last = (int) ($_SESSION['_last'] ?? 0);
        if ($created > 0 && ($now - $created > $this->absoluteSeconds || ($last > 0 && $now - $last > $this->idleSeconds))) {
            $this->invalidate();
            $_SESSION['_flash']['info'][] = 'Sua sessão expirou. Entre novamente.';
        }
        $_SESSION['_created'] ??= $now;
        $_SESSION['_last'] = $now;
        $this->handler->setUserId(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Regenera o ID (fixação de sessão) — chamar após login e elevação de privilégio. */
    public function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = $this->clock->now()->getTimestamp();
        unset($_SESSION['_csrf']);
    }

    public function login(int $userId): void
    {
        $this->regenerate();
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_at'] = $this->clock->now()->getTimestamp();
        $this->handler->setUserId($userId);
    }

    /** Logout real: remove dados e o registro no servidor. */
    public function invalidate(): void
    {
        $_SESSION = [];
        $this->handler->setUserId(null);
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = $this->clock->now()->getTimestamp();
    }

    public function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return is_int($id) ? $id : null;
    }

    public function sessionId(): ?string
    {
        $id = session_id();
        return $id === false || $id === '' ? null : $id;
    }

    /** Segundos desde a última autenticação (reautenticação para ações sensíveis). */
    public function secondsSinceAuth(): int
    {
        $at = (int) ($_SESSION['auth_at'] ?? 0);
        return $at > 0 ? $this->clock->now()->getTimestamp() - $at : PHP_INT_MAX;
    }

    public function markReauthenticated(): void
    {
        $_SESSION['auth_at'] = $this->clock->now()->getTimestamp();
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public function validCsrf(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /** @return array<string,list<string>> */
    public function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($f) ? $f : [];
    }

    /** Guarda entrada antiga e erros para reexibir formulário (sem campos de senha). */
    public function flashInput(array $input, array $errors): void
    {
        foreach (array_keys($input) as $k) {
            if (is_string($k) && preg_match('/pass|senha|token|code|otp|_csrf/i', $k)) {
                unset($input[$k]);
            }
        }
        $_SESSION['_old'] = $input;
        $_SESSION['_errors'] = $errors;
    }

    /** @return array{0:array<string,mixed>,1:array<string,list<string>>} */
    public function pullInput(): array
    {
        $old = $_SESSION['_old'] ?? [];
        $err = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_old'], $_SESSION['_errors']);
        return [is_array($old) ? $old : [], is_array($err) ? $err : []];
    }

    public function close(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
