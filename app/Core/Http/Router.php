<?php

declare(strict_types=1);

namespace Concursei\Core\Http;

/**
 * Roteador com tabela explícita. Parâmetros {nome} casam um segmento;
 * restrições opcionais {id:\d+}. Nunca converte caminho em include.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];
    /** @var array<string,Route> */
    private array $named = [];

    /** @param array{name?:string,middleware?:list<string>,cache?:string} $opts */
    public function add(string $method, string $pattern, array $handler, array $opts = []): Route
    {
        $route = new Route(strtoupper($method), $pattern, $handler, $opts['middleware'] ?? [], $opts['name'] ?? null, $opts['cache'] ?? 'private');
        $this->routes[] = $route;
        if ($route->name !== null) {
            $this->named[$route->name] = $route;
        }
        return $route;
    }

    public function get(string $p, array $h, array $o = []): Route
    {
        return $this->add('GET', $p, $h, $o);
    }

    public function post(string $p, array $h, array $o = []): Route
    {
        return $this->add('POST', $p, $h, $o);
    }

    public function put(string $p, array $h, array $o = []): Route
    {
        return $this->add('PUT', $p, $h, $o);
    }

    public function delete(string $p, array $h, array $o = []): Route
    {
        return $this->add('DELETE', $p, $h, $o);
    }

    /** @return array{0:Route,1:array<string,string>} */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        foreach ($this->routes as $route) {
            $params = $route->matches($path);
            if ($params === null) {
                continue;
            }
            if ($route->method === $lookup) {
                return [$route, $params];
            }
            $allowed[] = $route->method;
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'method_not_allowed', 'Método não permitido.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw HttpException::notFound();
    }

    /** @param array<string,string|int> $params */
    public function url(string $name, array $params = [], array $query = []): string
    {
        $route = $this->named[$name] ?? throw new \InvalidArgumentException('Rota desconhecida: ' . $name);
        $path = preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', static function (array $m) use ($params): string {
            if (!array_key_exists($m[1], $params)) {
                throw new \InvalidArgumentException('Parâmetro ausente: ' . $m[1]);
            }
            return rawurlencode((string) $params[$m[1]]);
        }, $route->pattern) ?? '/';
        return $query !== [] ? $path . '?' . http_build_query($query) : $path;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
