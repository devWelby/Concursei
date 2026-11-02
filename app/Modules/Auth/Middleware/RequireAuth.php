<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth\Middleware;

use Closure;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Middleware;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;
use Concursei\Shared\Auth\AuthUser;

/** Exige login. HTML GET: guarda destino (só caminho local) e redireciona para /entrar. */
final class RequireAuth implements Middleware
{
    public function __construct(private Session $session)
    {
    }

    public function handle(Request $request, Closure $next, ?string $arg = null): Response
    {
        if ($request->attribute('user') instanceof AuthUser) {
            return $next($request);
        }
        if ($request->wantsJson() || $request->method() !== 'GET') {
            throw HttpException::unauthorized();
        }
        $this->session->start();
        $query = http_build_query($request->allQuery());
        $this->session->set('intended', Response::safeLocalPath($request->path() . ($query !== '' ? '?' . $query : '')));
        $this->session->flash('info', 'Entre na sua conta para continuar.');
        return Response::redirect('/entrar');
    }
}
