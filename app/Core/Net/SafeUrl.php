<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

/**
 * URL HTTPS validada pela política do coletor (D-017).
 * - Só https, sem credenciais, sem fragmento, portas permitidas, até 2048 caracteres.
 * - Host é nome DNS ASCII (punycode) com TLD alfabético: IPs literais e formas numéricas
 *   alternativas (2130706433, 0x7f.1, [::1]) são recusados antes de qualquer resolução.
 * - Host precisa estar na allowlist: igual a uma entrada ou, com "*.dominio", o domínio e seus subdomínios.
 */
final class SafeUrl
{
    private function __construct(public readonly string $url, public readonly string $host, public readonly int $port)
    {
    }

    /**
     * @param list<string> $allowedHosts
     * @param list<int> $allowedPorts
     */
    public static function parse(string $url, array $allowedHosts, array $allowedPorts = [443]): self
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new UnsafeUrl('URL vazia, longa demais ou com caracteres não permitidos.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrl('URL inválida.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new UnsafeUrl('Somente HTTPS é permitido.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('URL com credenciais não é permitida.');
        }
        $host = rtrim(strtolower($parts['host']), '.');
        if (!self::isDnsName($host)) {
            throw new UnsafeUrl('Host precisa ser um nome de domínio (IP literal não é aceito).');
        }
        if (!self::hostAllowed($host, $allowedHosts)) {
            throw new UnsafeUrl("Domínio fora da lista autorizada: {$host}");
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if (!in_array($port, $allowedPorts, true)) {
            throw new UnsafeUrl("Porta não permitida: {$port}");
        }
        $path = $parts['path'] ?? '/';
        $normalized = 'https://' . $host . ($port !== 443 ? ':' . $port : '') . ($path === '' ? '/' : $path)
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return new self($normalized, $host, $port);
    }

    /** Resolve o Location de um redirecionamento em relação à URL atual (a política é aplicada depois). */
    public static function resolve(string $base, string $ref): string
    {
        $ref = trim($ref);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) {
            return $ref;
        }
        $b = parse_url($base);
        $origin = 'https://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            return 'https:' . $ref;
        }
        if (str_starts_with($ref, '/')) {
            return $origin . $ref;
        }
        if (str_starts_with($ref, '?')) {
            return $origin . ($b['path'] ?? '/') . $ref;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        $segments = [];
        foreach (explode('/', $dir . preg_replace('/[?#].*$/', '', $ref)) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }
        $query = str_contains($ref, '?') ? substr($ref, (int) strpos($ref, '?')) : '';
        return $origin . '/' . ltrim(implode('/', $segments), '/') . $query;
    }

    public static function isDnsName(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1
            && preg_match('/[a-z]/', substr($host, (int) strrpos($host, '.') + 1)) === 1;
    }

    /** @param list<string> $allowedHosts */
    public static function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === $host || (str_starts_with($allowed, '*.') && ($host === substr($allowed, 2) || str_ends_with($host, substr($allowed, 1))))) {
                return true;
            }
        }
        return false;
    }
}
