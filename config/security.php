<?php

declare(strict_types=1);

/** @var \Concursei\Core\Env $env */
return [
    // IPs/CIDRs de proxies confiáveis. Vazio = X-Forwarded-For é ignorado.
    'trusted_proxies' => $env->list('TRUSTED_PROXIES'),

    // Políticas de rate limit (janela fixa, persistida no banco). Valores iniciais conservadores.
    // methods: 'write' conta só POST/PUT/DELETE; 'all' conta também GET.
    'rate_limits' => [
        'default' => ['max' => 120, 'window' => 60, 'methods' => 'write'],
        'login' => ['max' => 10, 'window' => 900, 'methods' => 'write'],
        'register' => ['max' => 5, 'window' => 3600, 'methods' => 'write'],
        'password_reset' => ['max' => 5, 'window' => 3600, 'methods' => 'write'],
        'verify_email' => ['max' => 5, 'window' => 3600, 'methods' => 'write'],
        'mfa' => ['max' => 10, 'window' => 900, 'methods' => 'write'],
        'search' => ['max' => 60, 'window' => 60, 'methods' => 'all'],
        'ai_generation' => ['max' => 10, 'window' => 3600, 'methods' => 'write', 'per_user_max' => 5],
        'export' => ['max' => 20, 'window' => 3600, 'methods' => 'all', 'per_user_max' => 10],
        'admin' => ['max' => 300, 'window' => 300, 'methods' => 'write'],
        'public_form' => ['max' => 10, 'window' => 3600, 'methods' => 'write'],
    ],

    // Fontes extras de CSP (anúncios/analytics) — somente após consentimento e configuração do administrador.
    'csp_extra' => [],
];
