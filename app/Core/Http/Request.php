<?php

declare(strict_types=1);

namespace Concursei\Core\Http;

/**
 * Requisição HTTP imutável. Override de método (PUT/PATCH/DELETE) só via campo
 * _method em POST de formulário; nunca via GET. IP do cliente só considera
 * X-Forwarded-For quando o REMOTE_ADDR é um proxy confiável configurado.
 */
final class Request
{
    public const MAX_JSON_BYTES = 1_048_576;

    /** @var array<string,string> */
    private array $routeParams = [];
    /** @var array<string,mixed> */
    private array $attributes = [];
    private ?array $jsonCache = null;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $cookies
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $post = [],
        private array $cookies = [],
        private array $server = [],
        private array $files = [],
        private ?string $rawBody = null,
    ) {
        $this->method = strtoupper($method);
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST' && isset($_POST['_method']) && is_string($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        $raw = null;
        $ctype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_starts_with($ctype, 'application/json')) {
            $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
        }
        return new self($method, self::normalizePath($path), $_GET, $_POST, $_COOKIE, $_SERVER, $_FILES, $raw);
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim(rawurldecode($path), '/');
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isStateChanging(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    /** Campo de formulário (POST) ou JSON. */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        $json = $this->json();
        return $json[$key] ?? $default;
    }

    /** Valor escalar como string aparada (arrays são rejeitados). */
    public function string(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /**
     * Apenas os campos explicitamente listados (proteção contra mass assignment).
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $this->input($k);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        if ($this->rawBody === null || $this->rawBody === '') {
            return $this->jsonCache = [];
        }
        if (strlen($this->rawBody) > self::MAX_JSON_BYTES) {
            throw new HttpException(413, 'payload_too_large', 'Conteúdo muito grande.');
        }
        try {
            $data = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json', 'JSON inválido.');
        }
        return $this->jsonCache = is_array($data) ? $data : [];
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        }
        $v = $this->server[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public function server(string $key): ?string
    {
        $v = $this->server[$key] ?? null;
        return is_scalar($v) ? (string) $v : null;
    }

    public function wantsJson(): bool
    {
        $accept = (string) $this->header('Accept');
        return str_contains($accept, 'application/json')
            || str_starts_with((string) $this->header('Content-Type'), 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    /**
     * @param list<string> $trustedProxies IPs/CIDRs de proxies confiáveis.
     */
    public function clientIp(array $trustedProxies = []): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($trustedProxies === [] || !IpUtils::inAny($remote, $trustedProxies)) {
            return $remote;
        }
        $xff = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        $chain = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !IpUtils::inAny($ip, $trustedProxies)) {
                return $ip;
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function param(string $name): ?string
    {
        return $this->routeParams[$name] ?? null;
    }

    public function intParam(string $name): int
    {
        $v = $this->routeParams[$name] ?? '';
        if (!preg_match('/^[1-9]\d{0,18}$/', $v)) {
            throw new HttpException(404, 'not_found', 'Recurso não encontrado.');
        }
        return (int) $v;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return is_array($f) ? $f : null;
    }
}
