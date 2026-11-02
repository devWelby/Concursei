<?php

declare(strict_types=1);

namespace Concursei\Core\Security;

use Concursei\Core\Http\Response;

/**
 * Cabeçalhos de segurança + CSP com nonce por requisição. Domínios extras
 * (ex.: rede de anúncios) só entram via configuração administrativa explícita.
 */
final class SecurityHeaders
{
    private string $nonce;

    /**
     * @param array{script?:list<string>,img?:list<string>,frame?:list<string>,connect?:list<string>,style?:list<string>} $extraSources
     */
    public function __construct(private bool $hsts, private bool $https, private array $extraSources = [])
    {
        $this->nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    /** @param array{script?:list<string>,img?:list<string>,frame?:list<string>,connect?:list<string>,style?:list<string>} $sources */
    public function allowSources(array $sources): void
    {
        foreach ($sources as $type => $list) {
            foreach ($list as $src) {
                if (preg_match('#^https://[a-z0-9.-]+(\:\d+)?(/[A-Za-z0-9._~/-]*)?$#i', $src) || preg_match('#^https://\*\.[a-z0-9.-]+$#i', $src)) {
                    $this->extraSources[$type][] = $src;
                }
            }
        }
    }

    public function csp(): string
    {
        $x = fn (string $t): string => isset($this->extraSources[$t]) ? ' ' . implode(' ', array_unique($this->extraSources[$t])) : '';
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$this->nonce}'" . $x('script'),
            "style-src 'self'" . $x('style'),
            "img-src 'self' data:" . $x('img'),
            "font-src 'self'",
            "connect-src 'self'" . $x('connect'),
            "frame-src " . (isset($this->extraSources['frame']) ? trim($x('frame')) : "'none'"),
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];
        if ($this->https) {
            $directives[] = 'upgrade-insecure-requests';
        }
        return implode('; ', $directives);
    }

    public function apply(Response $response): Response
    {
        $response->header('Content-Security-Policy', $this->csp());
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $response->header('Cross-Origin-Opener-Policy', 'same-origin');
        $response->header('Cross-Origin-Resource-Policy', 'same-origin');
        if ($this->hsts && $this->https) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
