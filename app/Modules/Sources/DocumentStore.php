<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

use RuntimeException;

/**
 * Arquivos coletados, fora do webroot. O nome é o SHA-256 do conteúdo + extensão da allowlist:
 * nada vindo da fonte (nome, caminho, extensão) chega ao sistema de arquivos.
 */
class DocumentStore
{
    private const EXTENSIONS = [
        'application/pdf' => 'pdf', 'text/html' => 'html', 'application/xhtml+xml' => 'html', 'text/plain' => 'txt',
        'application/rss+xml' => 'xml', 'application/atom+xml' => 'xml', 'application/xml' => 'xml', 'text/xml' => 'xml',
    ];

    public function __construct(private string $dir)
    {
    }

    /** @return string caminho relativo gravado */
    public function put(string $sha256, string $mime, string $bytes): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256) || !isset(self::EXTENSIONS[$mime])) {
            throw new RuntimeException('Documento com hash ou tipo inválido.');
        }
        $relative = substr($sha256, 0, 2) . '/' . $sha256 . '.' . self::EXTENSIONS[$mime];
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0750, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Não foi possível criar o diretório de documentos.');
        }
        if (!is_file($path)) {
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Falha ao gravar documento.');
            }
            @chmod($path, 0640);
        }
        return $relative;
    }

    public function read(string $relative): string
    {
        if (!preg_match('#^[a-f0-9]{2}/[a-f0-9]{64}\.(pdf|html|txt|xml)$#', $relative)) {
            throw new RuntimeException('Caminho de documento inválido.');
        }
        $content = @file_get_contents($this->dir . '/' . $relative);
        if ($content === false) {
            throw new RuntimeException('Documento não encontrado no armazenamento.');
        }
        return $content;
    }
}
