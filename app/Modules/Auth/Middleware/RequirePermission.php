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

/**
 * Uso: 'can:admin.access'. Para permissões administrativas (equipe), exige
 * também MFA ativo — sem MFA, a equipe é levada à configuração.
 */
final class RequirePermission implements Middleware
{
    public function __construct(private Session $session)
    {
    }

    public function handle(Request $request, Closure $next, ?string $arg = null): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof AuthUser) {
            throw HttpException::unauthorized();
        }
        if ($arg === null || !$user->can($arg)) {
            throw HttpException::forbidden();
        }
        if ($user->isStaff() && !$user->mfaEnabled && str_starts_with($request->path(), '/admin')) {
            if ($request->wantsJson()) {
                throw new HttpException(403, 'mfa_required', 'Ative a verificação em duas etapas para acessar a administração.');
            }
            $this->session->flash('warning', 'A equipe precisa ativar a verificação em duas etapas antes de acessar a administração.');
            return Response::redirect('/conta/seguranca');
        }
        return $next($request);
    }
}
