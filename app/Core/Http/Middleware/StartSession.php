<?php

declare(strict_types=1);

namespace Concursei\Core\Http\Middleware;

use Closure;
use Concursei\Core\Http\Middleware;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;

/**
 * Inicia a sessão. Em rotas públicas cacheáveis ("lazy"), só inicia se o
 * navegador já tiver cookie de sessão — visitantes anônimos não recebem cookie.
 */
final class StartSession implements Middleware
{
    public function __construct(private Session $session)
    {
    }

    public function handle(Request $request, Closure $next, ?string $arg = null): Response
    {
        $lazy = $arg === 'lazy';
        if (!$lazy || $request->cookie($this->session->cookieName()) !== null || $request->isStateChanging()) {
            $this->session->start();
        }
        return $next($request);
    }
}
