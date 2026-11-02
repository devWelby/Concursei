<?php

declare(strict_types=1);

namespace Concursei\Core;

use Concursei\Core\Security\Crypto;

/**
 * Recusa inicialização insegura. Em produção, qualquer problema resulta em
 * 503 genérico ao público e diagnóstico apenas no log/CLI (sem valores).
 */
final class BootGuard
{
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'curl', 'fileinfo', 'ctype', 'libxml'];

    /** @return list<string> problemas encontrados (mensagens sem segredos) */
    public static function check(Config $config, string $basePath, ?string $documentRoot = null): array
    {
        $problems = [];
        $env = $config->string('app.env');
        if (!in_array($env, ['local', 'testing', 'staging', 'production'], true)) {
            $problems[] = 'APP_ENV inválido (use local, testing, staging ou production).';
        }
        if (PHP_VERSION_ID < 80200) {
            $problems[] = 'PHP 8.2 ou superior é obrigatório.';
        }
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $problems[] = "Extensão PHP ausente: {$ext}.";
            }
        }
        try {
            Crypto::decodeKey($config->string('app.key'));
        } catch (\RuntimeException) {
            $problems[] = 'APP_KEY ausente ou fraca (gere com: php bin/console key:generate).';
        }
        $url = $config->string('app.url');
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($urlPath, [null, '', '/'], true)
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_USER) !== null) {
            $problems[] = 'APP_URL inválida (ex.: https://www.example.com, sem caminho).';
        }
        $storage = $basePath . '/storage';
        foreach (['logs', 'cache', 'locks', 'exports', 'imports', 'private'] as $dir) {
            if (!is_dir($storage . '/' . $dir) || !is_writable($storage . '/' . $dir)) {
                $problems[] = "Diretório sem permissão de escrita: storage/{$dir}.";
            }
        }
        if ($env === 'production' || $env === 'staging') {
            if ($config->bool('app.debug')) {
                $problems[] = 'APP_DEBUG deve ser false em produção.';
            }
            if (!str_starts_with($url, 'https://')) {
                $problems[] = 'APP_URL deve usar https em produção.';
            }
            if ($config->string('database.password') === '' || $config->string('database.username') === 'root') {
                $problems[] = 'Credencial de banco ausente ou usuário root (use usuário com privilégio mínimo).';
            }
            // Privacidade e termos precisam identificar o responsável e o canal de contato (LGPD art. 9º e 41).
            if (trim($config->string('app.operator_name')) === '' || filter_var(trim($config->string('app.contact_email')), FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = 'OPERATOR_NAME e CONTACT_EMAIL (e-mail válido) são obrigatórios em produção: aparecem em privacidade, termos e correções.';
            }
            if ($documentRoot !== null && $documentRoot !== '') {
                $doc = realpath($documentRoot) ?: $documentRoot;
                $priv = realpath($basePath) ?: $basePath;
                if (str_starts_with(self::norm($priv) . '/', self::norm($doc) . '/')) {
                    $problems[] = 'O diretório privado da aplicação está dentro da raiz pública do servidor web. Apenas public/ pode ser exposto.';
                }
            }
        }
        return $problems;
    }

    private static function norm(string $p): string
    {
        return rtrim(str_replace('\\', '/', strtolower($p)), '/');
    }
}
