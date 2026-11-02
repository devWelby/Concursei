<?php

declare(strict_types=1);

/**
 * Roteador do servidor embutido do PHP — SOMENTE desenvolvimento local.
 * Reproduz as regras de public/.htaccess: arquivos estáticos existentes em public/
 * são servidos diretamente; arquivos ocultos, outros .php e extensões sensíveis
 * nunca são servidos; todo o resto vai para o front controller.
 *
 * Uso: php -S 127.0.0.1:8000 -t public scripts/dev/router.php   (ou: composer serve)
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$public = realpath(__DIR__ . '/../../public');
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$relative = ltrim(str_replace('\\', '/', $path), '/');

$isStatic = $relative !== ''
    && !preg_match('#(^|/)\.#', $relative)
    && preg_match('/\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|txt|xml|webmanifest|map)$/i', $relative);

if ($isStatic && $public !== false) {
    $file = realpath($public . DIRECTORY_SEPARATOR . $relative);
    if ($file !== false && is_file($file) && str_starts_with($file, $public . DIRECTORY_SEPARATOR)) {
        return false; // o servidor embutido entrega o arquivo com o Content-Type correto
    }
}

require $public . '/index.php';
