<?php

declare(strict_types=1);

namespace Concursei\Core\Http\Middleware;

use Closure;
use Concursei\Core\Config;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Middleware;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Security\RateLimiter;
use Concursei\Core\Session\Session;

/**
 * Rate limit por política nomeada (config/security.php → rate_limits).
 * Uso na rota: 'throttle:login'. Chave = IP pseudonimizado (+ usuário, se logado).
 * Executa antes do trabalho caro (hash de senha, IA, exportações).
 */
final class Throttle implements Middleware
{
    public function __construct(private RateLimiter $limiter, private Config $config, private Session $session)
    {
    }

    public function handle(Request $request, Closure $next, ?string $arg = null): Response
    {
        $policy = $arg ?? 'default';
        $cfg = $this->config->get('security.rate_limits.' . $policy);
        if (!is_array($cfg)) {
            throw new \RuntimeException('Política de rate limit inexistente: ' . $policy);
        }
        if (($cfg['methods'] ?? 'write') === 'write' && !$request->isStateChanging()) {
            return $next($request);
        }
        $ip = $request->clientIp($this->config->get('security.trusted_proxies', []));
        $identifier = 'ip:' . $ip;
        $result = $this->limiter->hit($policy, $identifier, (int) $cfg['max'], (int) $cfg['window']);
        if (!$result['allowed']) {
            throw HttpException::tooManyRequests($result['retry_after']);
        }
        $uid = $this->session->isStarted() ? $this->session->userId() : null;
        if ($uid !== null && isset($cfg['per_user_max'])) {
            $u = $this->limiter->hit($policy . ':user', 'user:' . $uid, (int) $cfg['per_user_max'], (int) $cfg['window']);
            if (!$u['allowed']) {
                throw HttpException::tooManyRequests($u['retry_after']);
            }
        }
        return $next($request);
    }
}
