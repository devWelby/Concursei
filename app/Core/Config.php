<?php

declare(strict_types=1);

namespace Concursei\Core;

/**
 * Configuração imutável em notação de ponto: $config->get('db.host').
 * Valores vêm de config/*.php (defaults públicos) + Env (segredos externos).
 */
final class Config
{
    /** @param array<string,mixed> $items */
    public function __construct(private array $items)
    {
    }

    public static function load(string $configDir, Env $env): self
    {
        $items = [];
        foreach (glob($configDir . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($name === 'routes') {
                continue;
            }
            $value = (static fn (string $__file, Env $env): mixed => require $__file)($file, $env);
            if (is_array($value)) {
                $items[$name] = $value;
            }
        }
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default);
        return is_bool($v) ? $v : $default;
    }

    /** Retorna cópia com valores sobrescritos (útil em testes). */
    public function with(string $key, mixed $value): self
    {
        $items = $this->items;
        $ref = &$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
        unset($ref);
        return new self($items);
    }
}
