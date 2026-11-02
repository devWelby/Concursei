<?php

declare(strict_types=1);

namespace Concursei\Tests\Support;

use Concursei\Core\Net\HttpTransport;
use Concursei\Core\Net\PinnedRequest;
use Concursei\Core\Net\RawResponse;
use Concursei\Core\Net\Resolver;
use Throwable;

/** Rede falsa para testes: DNS público por padrão e respostas preparadas por URL. Nenhum acesso real. */
final class FakeNetwork implements Resolver, HttpTransport
{
    /** @var array<string,list<string>> */
    public array $dns = [];
    /** @var array<string,RawResponse|Throwable> */
    private array $routes = [];
    /** @var list<PinnedRequest> */
    public array $requests = [];

    public function resolve(string $host): array
    {
        return $this->dns[$host] ?? ['200.160.2.3'];
    }

    public function send(PinnedRequest $request): RawResponse
    {
        $this->requests[] = $request;
        $route = $this->routes[$request->target->url] ?? new RawResponse(404, ['content-type' => 'text/html'], 'não encontrado', $request->ip);
        if ($route instanceof Throwable) {
            throw $route;
        }
        return $route;
    }

    /** @param array<string,string> $headers */
    public function on(string $url, int $status, string $body = '', array $headers = []): void
    {
        $this->routes[$url] = new RawResponse($status, array_change_key_case($headers), $body, '200.160.2.3');
    }

    public function fail(string $url, Throwable $e): void
    {
        $this->routes[$url] = $e;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static fn (PinnedRequest $r): string => $r->target->url, $this->requests);
    }
}
