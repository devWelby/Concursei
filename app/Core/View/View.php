<?php

declare(strict_types=1);

namespace Concursei\Core\View;

use InvalidArgumentException;
use RuntimeException;

/**
 * Templates PHP em resources/views. Nome de template validado por allowlist de
 * caracteres (sem "..", sem caminho absoluto) — nunca derivado da URL.
 * Toda saída nos templates deve passar por e()/e_url()/e_json().
 */
final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data);
    }

    /** @param array<string,mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $template)) {
            throw new InvalidArgumentException('Nome de template inválido.');
        }
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template não encontrado: ' . $template);
        }
        $vars = $data + $this->shared;
        $view = $this;
        ob_start();
        try {
            (static function (string $__file, array $__vars, View $view): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars, $view);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
