<?php

declare(strict_types=1);

namespace Concursei\Core\Http\Middleware;

use Closure;
use Concursei\Core\Config;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Middleware;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;

/**
 * CSRF (synchronizer token) para TODA requisição que altera estado, inclusive
 * AJAX e logout. Verificação adicional de Origin/Referer quando presentes.
 * Rotas públicas por token (ex.: descadastro) usam arg "token-route" e têm
 * modelo de ameaça próprio (token opaco de propósito único + confirmação POST).
 */
final class VerifyCsrf implements Middleware
{
    public function __construct(private Session $session, private Config $config)
    {
    }

    public function handle(Request $request, Closure $next, ?string $arg = null): Response
    {
        if (!$request->isStateChanging()) {
            return $next($request);
        }
        $this->assertSameOrigin($request);
        $token = $request->header('X-CSRF-Token') ?? (is_string($request->input('_csrf')) ? $request->input('_csrf') : null);
        if (!$this->session->isStarted() || !$this->session->validCsrf($token)) {
            throw new HttpException(403, 'csrf_failed', 'Sua sessão de formulário expirou. Recarregue a página e tente novamente.');
        }
        return $next($request);
    }

    private function assertSameOrigin(Request $request): void
    {
        $appUrl = $this->config->string('app.url');
        $expected = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME) . '://' . parse_url($appUrl, PHP_URL_HOST)
            . (parse_url($appUrl, PHP_URL_PORT) ? ':' . parse_url($appUrl, PHP_URL_PORT) : ''));
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== 'null') {
            if (strtolower(rtrim($origin, '/')) !== $expected) {
                throw new HttpException(403, 'csrf_origin', 'Origem da requisição não permitida.');
            }
            return;
        }
        $referer = $request->header('Referer');
        if ($referer !== null && $referer !== '') {
            $refOrigin = strtolower((string) parse_url($referer, PHP_URL_SCHEME) . '://' . parse_url($referer, PHP_URL_HOST)
                . (parse_url($referer, PHP_URL_PORT) ? ':' . parse_url($referer, PHP_URL_PORT) : ''));
            if ($refOrigin !== $expected) {
                throw new HttpException(403, 'csrf_origin', 'Origem da requisição não permitida.');
            }
        }
    }
}
