<?php

declare(strict_types=1);

namespace Concursei\Modules\Mail;

/**
 * Transporte de e-mail. "Enviado" significa aceito pelo servidor SMTP —
 * nunca significa entregue ou lido.
 */
interface Transport
{
    public function name(): string;

    public function isAvailable(): bool;

    /** @throws MailException */
    public function send(MailMessage $message): void;
}
