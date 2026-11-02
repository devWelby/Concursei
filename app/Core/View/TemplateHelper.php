<?php

declare(strict_types=1);

namespace Concursei\Core\View;

use Concursei\Core\Config;
use Concursei\Core\Http\Router;
use Concursei\Core\Session\Session;

/**
 * Helper disponível nos templates como $h. Todo HTML gerado aqui já é escapado.
 */
final class TemplateHelper
{
    private ?array $old = null;
    private ?array $errors = null;
    private ?array $flashes = null;

    public function __construct(private Session $session, private Router $router, private Config $config, private string $publicPath)
    {
    }

    /** Esquece flashes/entrada lidos na requisição anterior. */
    public function reset(): void
    {
        $this->old = $this->errors = $this->flashes = null;
    }

    public function csrfToken(): string
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }
        return $this->session->csrfToken();
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->csrfToken()) . '">';
    }

    public function methodField(string $method): string
    {
        $method = strtoupper($method);
        return in_array($method, ['PUT', 'PATCH', 'DELETE'], true) ? '<input type="hidden" name="_method" value="' . $method . '">' : '';
    }

    /** @param array<string,string|int> $params */
    public function url(string $name, array $params = [], array $query = []): string
    {
        return $this->router->url($name, $params, $query);
    }

    public function absoluteUrl(string $path): string
    {
        return rtrim($this->config->string('app.url'), '/') . '/' . ltrim($path, '/');
    }

    public function asset(string $path): string
    {
        if (!preg_match('#^[a-z0-9_./-]+$#i', $path) || str_contains($path, '..')) {
            return '';
        }
        $file = $this->publicPath . '/assets/' . $path;
        $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';
        return '/assets/' . $path . '?v=' . $v;
    }

    private function loadInput(): void
    {
        if ($this->old === null) {
            [$this->old, $this->errors] = $this->session->isStarted() ? $this->session->pullInput() : [[], []];
        }
    }

    public function old(string $field, mixed $default = ''): string
    {
        $this->loadInput();
        $v = $this->old[$field] ?? $default;
        return is_scalar($v) ? (string) $v : '';
    }

    public function error(string $field): ?string
    {
        $this->loadInput();
        return $this->errors[$field][0] ?? null;
    }

    public function hasErrors(): bool
    {
        $this->loadInput();
        return $this->errors !== [];
    }

    /** @return array<string,list<string>> */
    public function flashes(): array
    {
        if ($this->flashes === null) {
            $this->flashes = $this->session->isStarted() ? $this->session->pullFlash() : [];
        }
        return $this->flashes;
    }

    public function appName(): string
    {
        return $this->config->string('app.name', 'Concursei');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        // Somente chaves públicas de exibição.
        if (!preg_match('/^(app\.(name|url|locale|display_timezone|operator_name|contact_email)|site\.)/', $key)) {
            return $default;
        }
        return $this->config->get($key, $default);
    }

    public function isLoggedIn(): bool
    {
        return $this->session->isStarted() && $this->session->userId() !== null;
    }
}
