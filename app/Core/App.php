<?php

declare(strict_types=1);

namespace Concursei\Core;

use Closure;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Middleware;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Http\Route;
use Concursei\Core\Http\Router;
use Concursei\Core\Http\ValidationFailed;
use Concursei\Core\Logging\Logger;
use Concursei\Core\Security\SecurityHeaders;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use PDOException;
use Throwable;

/**
 * Kernel HTTP: roteamento → middlewares → controller → resposta padronizada.
 * Toda resposta recebe request ID, cabeçalhos de segurança e política de cache.
 */
final class App
{
    /** @var array<string,class-string<Middleware>> */
    private array $middlewareAliases = [];

    public function __construct(
        public readonly string $basePath,
        public readonly Config $config,
        public readonly Container $container,
    ) {
    }

    /** @var list<Closure> */
    private array $consoleRegistrars = [];
    /** @var list<string> middlewares globais adicionais (após sessão e CSRF) */
    private array $globalMiddleware = [];

    public function appendGlobalMiddleware(string $alias): void
    {
        $this->globalMiddleware[] = $alias;
    }

    /** @param class-string<Middleware> $class */
    public function aliasMiddleware(string $alias, string $class): void
    {
        $this->middlewareAliases[$alias] = $class;
    }

    /** Módulos registram comandos CLI: fn(Console $console): void */
    public function registerConsole(Closure $registrar): void
    {
        $this->consoleRegistrars[] = $registrar;
    }

    /** @return list<Closure> */
    public function consoleRegistrars(): array
    {
        return $this->consoleRegistrars;
    }

    public function router(): Router
    {
        /** @var Router */
        return $this->container->get(Router::class);
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);
        $logger->setRequestId($requestId);
        $headers = new SecurityHeaders(
            $this->config->bool('app.hsts'),
            str_starts_with($this->config->string('app.url'), 'https://'),
            $this->config->get('security.csp_extra', []),
        );
        $this->container->set(SecurityHeaders::class, $headers);
        /** @var View $view */
        $view = $this->container->get(View::class);
        $view->resetShared();
        $view->share('cspNonce', $headers->nonce());
        $view->share('requestId', $requestId);
        $request = $request->withAttribute('request_id', $requestId);

        $route = null;
        try {
            [$route, $params] = $this->router()->match($request->method(), $request->path());
            $request = $request->withRouteParams($params)->withAttribute('route', $route);
            $stack = array_merge([$route->cache === 'public' ? 'session:lazy' : 'session', 'csrf'], $this->globalMiddleware, $route->middleware);
            $response = $this->pipeline($stack, $request, fn (Request $r): Response => $this->dispatch($route, $r));
        } catch (Throwable $e) {
            $response = $this->renderException($e, $request, $requestId);
        }
        return $this->finalize($response, $route, $headers, $requestId);
    }

    /** @param list<string> $stack */
    private function pipeline(array $stack, Request $request, Closure $core): Response
    {
        $next = $core;
        foreach (array_reverse($stack) as $entry) {
            [$alias, $arg] = array_pad(explode(':', $entry, 2), 2, null);
            $class = $this->middlewareAliases[$alias] ?? throw new \RuntimeException('Middleware desconhecido: ' . $alias);
            $next = function (Request $r) use ($class, $arg, $next): Response {
                /** @var Middleware $mw */
                $mw = $this->container->get($class);
                return $mw->handle($r, $next, $arg);
            };
        }
        return $next($request);
    }

    private function dispatch(Route $route, Request $request): Response
    {
        [$class, $method] = $route->handler;
        $controller = $this->container->get($class);
        if (!is_callable([$controller, $method])) {
            throw new \RuntimeException('Ação de controller inválida.');
        }
        $result = $controller->$method($request);
        if (!$result instanceof Response) {
            throw new \RuntimeException('Controller deve retornar Response.');
        }
        return $result;
    }

    private function renderException(Throwable $e, Request $request, string $requestId): Response
    {
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);
        /** @var Session $session */
        $session = $this->container->get(Session::class);

        if ($e instanceof ValidationFailed && !$request->wantsJson() && $e->back !== null && $session->isStarted()) {
            $session->flashInput($e->input, $e->fieldErrors);
            $session->flash('error', $e->getMessage());
            return Response::redirect($e->back);
        }

        if ($e instanceof HttpException) {
            $status = $e->status;
            $code = $e->errorCode;
            $message = $e->getMessage();
            $fields = $e->fieldErrors;
            $extraHeaders = $e->headers;
            if ($status >= 500) {
                $logger->exception($e);
            } elseif (in_array($status, [403, 429], true)) {
                $logger->warning('Requisição recusada', ['status' => $status, 'code' => $code, 'path' => $request->path()]);
            }
        } elseif ($e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [2002, 2003, 2006, 1040, 1203], true)) {
            $logger->exception($e, 'Banco de dados indisponível');
            [$status, $code, $message, $fields, $extraHeaders] = [503, 'service_unavailable', 'Serviço temporariamente indisponível. Tente novamente em instantes.', [], ['Retry-After' => '30']];
        } else {
            $logger->exception($e);
            [$status, $code, $message, $fields, $extraHeaders] = [500, 'internal_error', 'Ocorreu um erro inesperado. Se persistir, informe o código da requisição.', [], []];
        }

        $debug = $this->config->bool('app.debug') && $this->config->string('app.env') === 'local';
        if ($request->wantsJson()) {
            $payload = ['error' => ['code' => $code, 'message' => $message, 'request_id' => $requestId]];
            if ($fields !== []) {
                $payload['error']['fields'] = $fields;
            }
            if ($debug && !$e instanceof HttpException) {
                $payload['error']['debug'] = $e::class . ': ' . Logger::redactString($e->getMessage());
            }
            $response = Response::json($payload, $status);
        } else {
            try {
                /** @var View $view */
                $view = $this->container->get(View::class);
                $html = $view->render('errors/http', [
                    'status' => $status, 'message' => $message, 'fields' => $fields, 'requestId' => $requestId,
                    'debug' => ($debug && !$e instanceof HttpException) ? $e::class . ': ' . Logger::redactString($e->getMessage()) : null,
                    'title' => 'Erro ' . $status,
                ]);
            } catch (Throwable $viewError) {
                $logger->exception($viewError, 'Falha ao renderizar página de erro');
                $html = '<!doctype html><meta charset="utf-8"><title>Erro</title><p>' . e($message) . '</p><p>Código: ' . e($requestId) . '</p>';
            }
            $response = Response::html($html, $status);
        }
        foreach ($extraHeaders as $k => $v) {
            $response->header($k, $v);
        }
        return $response;
    }

    private function finalize(Response $response, ?Route $route, SecurityHeaders $headers, string $requestId): Response
    {
        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $headers->apply($response);
        $response->header('X-Request-Id', $requestId);
        if ($response->getHeader('Cache-Control') === null) {
            $publicOk = $route !== null && $route->cache === 'public' && !$session->isStarted() && $response->status() === 200;
            $response->header('Cache-Control', $publicOk ? 'public, max-age=300' : 'private, no-store');
            $response->header('Vary', 'Cookie');
        }
        if ($route !== null && $route->cache !== 'public') {
            $response->header('X-Robots-Tag', 'noindex');
        }
        $session->close();
        return $response;
    }
}
