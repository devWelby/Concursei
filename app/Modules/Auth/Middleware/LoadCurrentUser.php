<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth\Middleware;

use Closure;
use Concursei\Core\Http\Middleware;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Modules\Auth\UserRepository;
use Concursei\Shared\Auth\AuthUser;

/**
 * Global: carrega o usuário da sessão a cada requisição (estado sempre do banco;
 * conta suspensa/excluída perde a sessão imediatamente).
 */
final class LoadCurrentUser implements Middleware
{
    public function __construct(private Session $session, private UserRepository $users, private View $view)
    {
    }

    public function handle(Request $request, Closure $next, ?string $arg = null): Response
    {
        $user = null;
        if ($this->session->isStarted() && ($id = $this->session->userId()) !== null) {
            $row = $this->users->findById($id);
            if ($row !== null && $row['status'] === 'active') {
                $user = AuthUser::fromRow($row);
            } else {
                $this->session->invalidate();
            }
        }
        $this->view->share('currentUser', $user?->toView());
        return $next($request->withAttribute('user', $user));
    }
}
