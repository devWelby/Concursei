<?php

declare(strict_types=1);

/**
 * Front controller. Único ponto de entrada PHP exposto à web.
 *
 * Diretório privado da aplicação (app/, config/, vendor/, storage/...), em ordem:
 *  1. variável de ambiente CONCURSEI_APP_ROOT (ex.: SetEnv no .htaccess);
 *  2. pasta-mãe deste arquivo (desenvolvimento: Concursei/public/index.php);
 *  3. pasta "concursei" ao lado do webroot (HostGator: ~/public_html + ~/concursei).
 */

$candidates = array_filter([
    getenv('CONCURSEI_APP_ROOT') ?: null,
    dirname(__DIR__),
    dirname(__DIR__) . '/concursei',
]);
$appRoot = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/bootstrap/app.php') && is_file($candidate . '/vendor/autoload.php')) {
        $appRoot = $candidate;
        break;
    }
}

$unavailable = static function (): never {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Retry-After: 120');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Indisponível</title>'
        . '<p>Serviço temporariamente indisponível. Tente novamente em alguns minutos.</p></html>';
    exit;
};

if ($appRoot === null) {
    error_log('[concursei] diretório privado da aplicação não encontrado');
    $unavailable();
}

require $appRoot . '/vendor/autoload.php';

try {
    /** @var \Concursei\Core\App $app */
    $app = (require $appRoot . '/bootstrap/app.php')($appRoot);
} catch (\Throwable $e) {
    error_log('[concursei] falha no bootstrap: ' . $e::class);
    $unavailable();
}

$problems = \Concursei\Core\BootGuard::check($app->config, $appRoot, $_SERVER['DOCUMENT_ROOT'] ?? null);
if ($problems !== []) {
    $logger = $app->container->get(\Concursei\Core\Logging\Logger::class);
    $logger->error('Inicialização recusada por configuração insegura/incompleta', ['problems' => $problems]);
    if ($app->config->string('app.env') === 'local' && $app->config->bool('app.debug')) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Configuração incompleta (somente em APP_ENV=local):\n- " . implode("\n- ", $problems) . "\n";
        exit;
    }
    $unavailable();
}

$request = \Concursei\Core\Http\Request::fromGlobals();
$response = $app->handle($request);
$response->send($request->method() === 'HEAD');
