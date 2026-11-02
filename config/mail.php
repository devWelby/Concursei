<?php

declare(strict_types=1);

/** @var \Concursei\Core\Env $env */
return [
    // smtp (envio real) | capture (dev/testes: grava em storage/mail, nunca envia) | disabled
    // Em produção, qualquer valor diferente de smtp torna o envio INDISPONÍVEL (sem falso sucesso).
    'transport' => $env->string('MAIL_TRANSPORT', 'disabled'),
    'host' => $env->string('SMTP_HOST', ''),
    'port' => $env->int('SMTP_PORT', 587),
    // starttls (porta 587) | smtps (porta 465). Não misture.
    'encryption' => $env->string('SMTP_ENCRYPTION', 'starttls'),
    'username' => $env->string('SMTP_USERNAME', ''),
    'password' => $env->string('SMTP_PASSWORD', ''),
    'timeout' => $env->int('SMTP_TIMEOUT', 10),
    'from_address' => $env->string('MAIL_FROM_ADDRESS', ''),
    'from_name' => $env->string('MAIL_FROM_NAME', 'Concursei'),
    // Limite conservador por execução do Cron (respeite o limite do provedor/hospedagem).
    'batch_size' => $env->int('MAIL_BATCH_SIZE', 20),
    'max_attempts' => 5,
];
