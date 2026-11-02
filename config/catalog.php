<?php

declare(strict_types=1);

/** @var \Concursei\Core\Env $env */
return [
    // Revisão em duas pessoas: quem enviou para revisão não pode publicar a mesma versão.
    // Desligado por padrão (operação com uma única pessoa na equipe); ligue quando houver revisores.
    'four_eyes' => $env->bool('CATALOG_FOUR_EYES', false),
    // Concursos publicados sem nova conferência humana há mais dias que isto aparecem no painel da equipe.
    'reverify_days' => 30,
    'per_page' => 20,
    // Limite de concursos acompanhados por conta (evita abuso; o painel continua legível).
    'max_favorites' => 200,
];
