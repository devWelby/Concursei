<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

use Concursei\Core\Config;

/**
 * Cliente HTTP do coletor, protegido contra SSRF (D-017). A cada salto (inclusive redirecionamentos):
 * valida a URL e a allowlist de hosts, resolve o DNS, exige que TODOS os endereços sejam públicos e
 * fixa a conexão em um deles. Novas tentativas passam de novo por todo o fluxo.
 */
class SafeHttpClient
{
    public const DOCUMENT_TYPES = ['text/html', 'application/xhtml+xml', 'application/pdf', 'text/plain', 'application/rss+xml', 'application/atom+xml', 'application/xml', 'text/xml'];

    public function __construct(private Resolver $resolver, private HttpTransport $transport, private Config $config)
    {
    }

    /**
     * @param list<string> $allowedHosts hosts autorizados pelo administrador (aceita "*.dominio")
     * @param array{etag?:?string,last_modified?:?string,accept?:list<string>,max_bytes?:int} $options
     */
    public function get(string $url, array $allowedHosts, array $options = []): FetchResult
    {
        $headers = ['Accept' => 'text/html, application/pdf;q=0.9, */*;q=0.5', 'Accept-Language' => 'pt-BR, pt;q=0.9'];
        if (($options['etag'] ?? null) !== null) {
            $headers['If-None-Match'] = (string) $options['etag'];
        }
        if (($options['last_modified'] ?? null) !== null) {
            $headers['If-Modified-Since'] = (string) $options['last_modified'];
        }
        $accept = $options['accept'] ?? self::DOCUMENT_TYPES;
        $maxBytes = $options['max_bytes'] ?? $this->config->int('collector.max_bytes', 15 * 1024 * 1024);
        $maxRedirects = $this->config->int('collector.max_redirects', 3);
        $ports = $this->config->get('collector.allowed_ports', [443]);
        $redirects = [];

        for ($hop = 0; ; $hop++) {
            $target = SafeUrl::parse($url, $allowedHosts, is_array($ports) ? array_map('intval', $ports) : [443]);
            $ip = $this->pick($target->host);
            $response = $this->transport->send(new PinnedRequest(
                $target, $ip, $headers, $accept, $maxBytes,
                $this->config->int('collector.connect_timeout', 5),
                $this->config->int('collector.timeout', 30),
            ));

            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('location');
                if ($location === null || $location === '') {
                    throw new FetchFailed('Redirecionamento sem destino.', false, $response->status);
                }
                if ($hop >= $maxRedirects) {
                    throw new UnsafeUrl('Redirecionamentos demais.');
                }
                $redirects[] = $target->url;
                $url = SafeUrl::resolve($target->url, $location);
                continue;
            }
            if ($response->status === 304) {
                return new FetchResult(304, $target->url, '', '', $response->header('etag'), $response->header('last-modified'), $redirects);
            }
            if ($response->status < 200 || $response->status > 299) {
                $retryable = in_array($response->status, [408, 425, 429], true) || $response->status >= 500;
                throw new FetchFailed('Resposta HTTP ' . $response->status . '.', $retryable, $response->status, self::retryAfter($response->header('retry-after')));
            }
            $mime = $response->mimeType();
            if (!in_array($mime, $accept, true)) {
                throw new FetchFailed('Tipo de conteúdo não aceito: ' . ($mime !== '' ? $mime : 'ausente') . '.', false, $response->status);
            }
            return new FetchResult($response->status, $target->url, $response->body, $mime, $response->header('etag'), $response->header('last-modified'), $redirects);
        }
    }

    /** Todos os endereços precisam ser públicos (um único interno já recusa); prefere IPv4. */
    private function pick(string $host): string
    {
        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new FetchFailed('Domínio não resolvido.', true);
        }
        foreach ($ips as $ip) {
            if (!IpGuard::isPublic($ip)) {
                throw new UnsafeUrl("Domínio {$host} aponta para endereço não público.");
            }
        }
        foreach ($ips as $ip) {
            if (!IpGuard::isIpv6($ip)) {
                return $ip;
            }
        }
        return $ips[0];
    }

    public static function retryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (ctype_digit(trim($value))) {
            return (int) trim($value);
        }
        $ts = strtotime($value);
        return $ts !== false ? max(0, $ts - time()) : null;
    }
}
