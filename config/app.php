<?php

declare(strict_types=1);

/**
 * Configuração pública da aplicação. Segredos vêm SOMENTE do ambiente/.env privado.
 * @var \Concursei\Core\Env $env
 */
return [
    'name' => $env->string('APP_NAME', 'Concursei'),
    'env' => $env->string('APP_ENV', 'production'),
    'debug' => $env->bool('APP_DEBUG', false),
    'url' => rtrim($env->string('APP_URL', 'http://localhost:8000'), '/'),
    'key' => $env->string('APP_KEY', ''),
    'locale' => 'pt-BR',
    // Exibição; timestamps técnicos são sempre UTC no banco.
    'display_timezone' => $env->string('APP_DISPLAY_TIMEZONE', 'America/Sao_Paulo'),
    'hsts' => $env->bool('APP_HSTS', false),
    'log_level' => $env->string('LOG_LEVEL', 'info'),
    // Destino padrão após login (caminho local).
    'home_after_login' => '/painel',
    // Identidade do operador (exibida em privacidade/termos). Vazio = pendência de configuração.
    'operator_name' => $env->string('OPERATOR_NAME', ''),
    'contact_email' => $env->string('CONTACT_EMAIL', ''),
];
