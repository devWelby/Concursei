<?php

declare(strict_types=1);

/**
 * Integração de IA (D-018). A chave fica SOMENTE no .env privado do servidor: nunca no navegador,
 * no repositório, em URL ou em log (vai no cabeçalho x-goog-api-key).
 * @var \Concursei\Core\Env $env
 */
return [
    // rest = API real | disabled | fake = somente APP_ENV=testing (fora disso vira disabled).
    'transport' => $env->string('GEMINI_TRANSPORT', 'rest'),
    'api_key' => $env->string('GEMINI_API_KEY', ''),
    // Sem valor padrão de propósito: escolha um modelo estável vigente na documentação oficial.
    'model' => $env->string('GEMINI_MODEL', ''),
    'endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
    'timeout' => $env->int('GEMINI_TIMEOUT', 90),

    // Tetos conservadores em tokens (entrada + saída, inclusive "raciocínio"). O custo em reais depende
    // do preço vigente do modelo escolhido; converta e ajuste antes de ativar em produção.
    'daily_token_limit' => $env->int('AI_DAILY_BUDGET', 1000000),
    'monthly_token_limit' => $env->int('AI_MONTHLY_BUDGET', 10000000),
    'daily_call_limit' => $env->int('AI_DAILY_CALL_LIMIT', 200),

    // Extração de editais (D-019): texto enviado por chamada (acima disso, só as páginas mais relevantes)
    // e limite de saída. Ajuste o limite de saída ao máximo documentado do modelo escolhido.
    'edital_max_input_chars' => $env->int('AI_EDITAL_MAX_INPUT_CHARS', 120000),
    'edital_max_output_tokens' => $env->int('AI_EDITAL_MAX_OUTPUT_TOKENS', 8192),

    // Circuit breaker: após N falhas seguidas do provedor, pausa as chamadas por um tempo.
    'circuit_failures' => 5,
    'circuit_cooldown_seconds' => 900,
];
