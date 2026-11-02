<?php

declare(strict_types=1);

/**
 * Fila e agenda do Cron (D-016). Valores iniciais conservadores para hospedagem compartilhada:
 * ajuste após medir o tempo máximo real do PHP CLI na conta.
 * @var \Concursei\Core\Env $env
 */
return [
    // Reserva de um job em execução; deve ser maior que o tempo de qualquer handler.
    'lease_seconds' => $env->int('JOBS_LEASE_SECONDS', 300),
    'max_payload_bytes' => 16384,
    // Retenção (dias) de jobs concluídos e de falhos/cancelados.
    'keep_completed_days' => 7,
    'keep_failed_days' => 30,

    // Um único Cron chama "schedule:run" (ex.: a cada 5 min). Cada tarefa roda com lock próprio.
    // every_minutes: intervalo mínimo entre execuções. daily_at: horário local (APP_DISPLAY_TIMEZONE).
    'schedule' => [
        // Enfileirar antes de queue:work para a coleta rodar na mesma chamada.
        'sources:dispatch-due' => ['every_minutes' => 15],
        'queue:work' => ['every_minutes' => 1, 'options' => ['max-jobs' => $env->int('JOBS_MAX_PER_RUN', 5), 'max-seconds' => $env->int('JOBS_MAX_SECONDS', 40)]],
        'mail:send-pending' => ['every_minutes' => 5],
        'catalog:refresh' => ['daily_at' => '00:05'],
        'tokens:purge' => ['daily_at' => '03:00'],
        'session:gc' => ['daily_at' => '03:10'],
        'ratelimit:purge' => ['daily_at' => '03:20'],
        'jobs:purge' => ['daily_at' => '03:30'],
    ],
    // Lock máximo de uma tarefa agendada (recuperado se o processo morrer).
    'task_lock_seconds' => 900,
];
