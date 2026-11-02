<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Core\Config;
use Concursei\Core\Console\Console;
use Concursei\Core\Container;
use Concursei\Core\Logging\Logger;
use Concursei\Modules\Mail\CaptureTransport;
use Concursei\Modules\Mail\DisabledTransport;
use Concursei\Modules\Mail\Outbox;
use Concursei\Modules\Mail\SmtpTransport;
use Concursei\Modules\Mail\Transport;

return static function (App $app, Container $c, Config $config): void {
    $c->singleton(Transport::class, static function (Container $c) use ($config, $app): Transport {
        $env = $config->string('app.env');
        return match ($config->string('mail.transport')) {
            'smtp' => new SmtpTransport($config, $c->get(Logger::class)),
            // Captura nunca é aceita em produção/homologação: evitaria "sucesso" falso.
            'capture' => in_array($env, ['production', 'staging'], true)
                ? new DisabledTransport()
                : new CaptureTransport($env === 'local' ? $app->basePath . '/storage/mail' : null),
            default => new DisabledTransport(),
        };
    });

    $app->registerConsole(static function (Console $console) use ($c): void {
        $console->register('mail:send-pending', 'Envia e-mails pendentes do outbox (Cron). --limit=N', static function (array $o, array $a, Console $io) use ($c): int {
            $stats = $c->get(Outbox::class)->dispatch(isset($o['limit']) ? max(1, (int) $o['limit']) : null);
            if ($stats['skipped']) {
                $io->error('Envio indisponível: transporte de e-mail não configurado (MAIL_TRANSPORT/SMTP_*).');
                return 1;
            }
            $io->line(sprintf('Enviados: %d · reagendados: %d · falhas definitivas: %d', $stats['sent'], $stats['retry'], $stats['failed']));
            return 0;
        });
    });
};
