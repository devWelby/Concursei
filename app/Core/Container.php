<?php

declare(strict_types=1);

namespace Concursei\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Contêiner mínimo com singletons e autowiring por tipo de construtor.
 * Só instancia classes do namespace Concursei (evita construção arbitrária).
 */
final class Container
{
    /** @var array<string,Closure> */
    private array $factories = [];
    /** @var array<string,object> */
    private array $instances = [];

    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|object
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        return $this->instances[$id] = $this->build($id);
    }

    private function build(string $class): object
    {
        if (!str_starts_with($class, 'Concursei\\') || !class_exists($class)) {
            throw new RuntimeException('Serviço não registrado: ' . $class);
        }
        $ref = new ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new RuntimeException('Classe não instanciável: ' . $class);
        }
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return new $class();
        }
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new RuntimeException("Dependência não resolvível: {$class}::\${$param->getName()}");
            }
        }
        return $ref->newInstanceArgs($args);
    }
}
