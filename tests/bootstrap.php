<?php

declare(strict_types=1);

/**
 * Bootstrap de testes. Credenciais do banco de teste vêm de variáveis de
 * ambiente (CI) ou do arquivo local .env.testing (ignorado pelo Git).
 * APP_KEY de teste é gerada a cada execução — nenhuma chave fixa no repositório.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$envTesting = dirname(__DIR__) . '/.env.testing';
if (is_file($envTesting)) {
    foreach (\Concursei\Core\Env::parse((string) file_get_contents($envTesting)) as $k => $v) {
        if (getenv($k) === false) {
            putenv($k . '=' . $v);
        }
    }
}
if (getenv('APP_KEY') === false || getenv('APP_KEY') === '') {
    putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));
}

// Trava de segurança: os testes de integração recriam o schema; nunca rodar contra banco que não seja de teste.
$db = (string) getenv('DB_DATABASE');
if ($db !== '' && !str_ends_with($db, '_test')) {
    fwrite(STDERR, "Recusado: DB_DATABASE de teste deve terminar com _test.\n");
    exit(1);
}
