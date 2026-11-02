<?php

declare(strict_types=1);

namespace Concursei\Core\Http;

/**
 * Resposta HTTP. Redirecionamentos só para caminhos locais (sem open redirect).
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];
    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $k => $v) {
            $this->header($k, $v);
        }
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $text, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($text, $status, ['Content-Type' => $type . '; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /** Redireciona apenas para caminho local; qualquer outro destino vira "/". */
    public static function redirect(string $path, int $status = 303): self
    {
        return new self('', $status, ['Location' => self::safeLocalPath($path)]);
    }

    public static function safeLocalPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $path)) {
            return '/';
        }
        return $path;
    }

    public static function download(string $content, string $filename, string $contentType): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'arquivo';
        return new self($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function header(string $name, string $value): self
    {
        // Impede injeção de cabeçalho (CRLF).
        $this->headers[$name] = str_replace(["\r", "\n"], '', $value);
        return $this;
    }

    public function withoutHeader(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param array<string,mixed> $options */
    public function cookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(bool $isHead = false): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v, true);
            }
            foreach ($this->cookies as $c) {
                setcookie($c['name'], $c['value'], $c['options']);
            }
        }
        if (!$isHead) {
            echo $this->body;
        }
    }
}
