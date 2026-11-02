<?php

declare(strict_types=1);

namespace Concursei\Core;

/**
 * Leitor mínimo de arquivo .env (KEY=VALUE). Não usa putenv() para não vazar
 * segredos a processos filhos; variáveis reais do ambiente têm precedência.
 */
final class Env
{
    /** @var array<string,string> */
    private array $values = [];

    /** @param array<string,string> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public static function fromFile(?string $path): self
    {
        $values = [];
        if ($path !== null && is_file($path) && is_readable($path)) {
            $values = self::parse((string) file_get_contents($path));
        }
        return new self($values);
    }

    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        $out = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            $raw = trim(substr($line, $pos + 1));
            if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
                $quote = $raw[0];
                $end = strrpos($raw, $quote);
                $value = $end > 0 ? substr($raw, 1, $end - 1) : substr($raw, 1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $value);
                }
            } else {
                $hash = strpos($raw, ' #');
                $value = $hash !== false ? rtrim(substr($raw, 0, $hash)) : $raw;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return $this->values[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->get($key, $default) ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'sim'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return ($v !== null && preg_match('/^-?\d+$/', $v)) ? (int) $v : $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $v = $this->get($key, '') ?? '';
        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn ($s) => $s !== ''));
    }
}
