<?php

declare(strict_types=1);

namespace Concursei\Core\Logging;

use Throwable;

/**
 * Logger JSON Lines com redação de segredos e dados pessoais.
 * Nunca registra senhas, tokens, cookies, chaves ou e-mails completos.
 */
class Logger
{
    private const SENSITIVE_KEYS = '/pass|secret|token|cookie|authorization|api[_-]?key|csrf|session|otp|totp|recovery|private|credential|signature/i';

    private string $requestId = '-';

    public function __construct(private string $dir, private string $minLevel = 'info')
    {
    }

    public function setRequestId(string $id): void
    {
        $this->requestId = $id;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function debug(string $msg, array $ctx = []): void
    {
        $this->log('debug', $msg, $ctx);
    }

    public function info(string $msg, array $ctx = []): void
    {
        $this->log('info', $msg, $ctx);
    }

    public function warning(string $msg, array $ctx = []): void
    {
        $this->log('warning', $msg, $ctx);
    }

    public function error(string $msg, array $ctx = []): void
    {
        $this->log('error', $msg, $ctx);
    }

    public function exception(Throwable $e, string $msg = 'Exceção não tratada', array $ctx = []): void
    {
        $ctx['exception'] = [
            'class' => $e::class,
            'message' => self::redactString($e->getMessage()),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => array_slice(array_map(
                static fn (array $f): string => (isset($f['class']) ? $f['class'] . '::' : '') . ($f['function'] ?? '?') . (isset($f['line']) ? ':' . $f['line'] : ''),
                $e->getTrace()
            ), 0, 12),
        ];
        $this->log('error', $msg, $ctx);
    }

    public function log(string $level, string $msg, array $ctx = []): void
    {
        $order = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        if (($order[$level] ?? 1) < ($order[$this->minLevel] ?? 1)) {
            return;
        }
        $record = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'request_id' => $this->requestId,
            'msg' => self::redactString($msg),
            'ctx' => self::redact($ctx),
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        $file = $this->dir . '/app-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('[concursei] ' . $line);
        }
    }

    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEYS, $key) === 1;
    }

    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '[...]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = (is_string($k) && preg_match(self::SENSITIVE_KEYS, $k)) ? '[REDACTED]' : self::redact($v, $depth + 1);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::redactString($value);
        }
        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }
        return $value;
    }

    public static function redactString(string $s): string
    {
        $s = mb_substr($s, 0, 2000);
        // e-mails -> f***@d***
        $s = (string) preg_replace_callback('/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9])[A-Za-z0-9.-]*\.[A-Za-z]{2,}/', static fn (array $m): string => $m[1] . '***@' . $m[2] . '***', $s);
        // chaves/tokens conhecidos
        $s = (string) preg_replace('/AIza[0-9A-Za-z_\-]{20,}|\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_.-]+|\b(?:sk|rk)_live_[A-Za-z0-9]+/', '[REDACTED]', $s);
        // key=valor em query strings/mensagens
        $s = (string) preg_replace('/((?:key|token|password|senha|secret|signature)=)[^&\s"\']+/i', '$1[REDACTED]', $s);
        return $s;
    }
}
