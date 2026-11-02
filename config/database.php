<?php

declare(strict_types=1);

/** @var \Concursei\Core\Env $env */
return [
    'host' => $env->string('DB_HOST', '127.0.0.1'),
    'port' => $env->int('DB_PORT', 3306),
    'database' => $env->string('DB_DATABASE', 'concursei'),
    'username' => $env->string('DB_USERNAME', 'concursei'),
    'password' => $env->string('DB_PASSWORD', ''),
];
