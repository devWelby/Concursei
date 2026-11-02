<?php

declare(strict_types=1);

/** @var \Concursei\Core\Env $env */
return [
    // Expiração por inatividade e absoluta (segundos).
    'idle_seconds' => $env->int('SESSION_IDLE_SECONDS', 7200),
    'absolute_seconds' => $env->int('SESSION_ABSOLUTE_SECONDS', 1209600),
    // Ações sensíveis (troca de senha/e-mail, MFA, exclusão) exigem login recente.
    'reauth_seconds' => $env->int('SESSION_REAUTH_SECONDS', 900),
];
