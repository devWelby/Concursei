<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

use Concursei\Core\Container;
use InvalidArgumentException;

/** Allowlist tipo → handler. Os módulos registram seus tipos; tipo desconhecido não entra na fila. */
final class JobRegistry
{
    /** @var array<string,class-string<JobHandler>> */
    private array $handlers = [];

    public function __construct(private Container $container)
    {
    }

    /** @param class-string<JobHandler> $handlerClass */
    public function register(string $type, string $handlerClass): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $type) || strlen($type) > 64) {
            throw new InvalidArgumentException("Tipo de job inválido: {$type}");
        }
        $this->handlers[$type] = $handlerClass;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function handler(string $type): JobHandler
    {
        if (!isset($this->handlers[$type])) {
            throw new JobFailed("Tipo de job sem handler: {$type}");
        }
        $handler = $this->container->get($this->handlers[$type]);
        if (!$handler instanceof JobHandler) {
            throw new JobFailed("Handler inválido para {$type}");
        }
        return $handler;
    }

    /** @return list<string> */
    public function types(): array
    {
        $types = array_keys($this->handlers);
        sort($types);
        return $types;
    }
}
