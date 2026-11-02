<?php

declare(strict_types=1);

/**
 * Coletor de fontes oficiais (D-017). Hosts permitidos vêm do cadastro de fontes feito pela equipe.
 * @var \Concursei\Core\Env $env
 */
return [
    'max_bytes' => $env->int('COLLECTOR_MAX_BYTES', 15 * 1024 * 1024),
    'connect_timeout' => 5,
    'timeout' => $env->int('COLLECTOR_TIMEOUT', 30),
    'max_redirects' => 3,
    'allowed_ports' => [443],
    // Identificação honesta do robô; o contato é o endereço público do site.
    'user_agent' => 'ConcurseiBot/1.0 (+' . rtrim($env->string('APP_URL', 'http://localhost:8000'), '/') . '/sobre)',
];
