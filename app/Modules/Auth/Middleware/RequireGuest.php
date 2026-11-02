<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth\Middleware;

use Closure;
use Concursei\Core\Config;
use Concursei\Core\Http\Middleware;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Shared\Auth\AuthUser;

/** Páginas de login/cadastro: quem já está logado vai para a área inicial. */
final class RequireGuest implements Middleware
{
    public function __construct(private Config $config)
    {
    }

    public function handle(Request $request, Closure $next, ?string $arg = null): Response
    {
        if ($request->attribute('user') instanceof AuthUser) {
            return Response::redirect($this->config->string('app.home_after_login', '/painel'));
        }
        return $next($request);
    }
}
