<?php

declare(strict_types=1);

namespace Concursei\Modules\Mail;

/** Envio desativado/não configurado: falha explícita, sem fingir sucesso. */
final class DisabledTransport implements Transport
{
    public function name(): string
    {
        return 'disabled';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function send(MailMessage $message): void
    {
        throw new MailException('Envio de e-mail não configurado.');
    }
}
