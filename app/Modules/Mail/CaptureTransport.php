<?php

declare(strict_types=1);

namespace Concursei\Modules\Mail;

/**
 * Captura local (desenvolvimento/testes): NUNCA envia. Guarda em memória e,
 * quando há diretório, grava .eml em storage/mail (ignorado pelo Git).
 * Proibido em produção (ver MailModule).
 */
final class CaptureTransport implements Transport
{
    /** @var list<MailMessage> */
    public static array $sent = [];

    public function __construct(private ?string $dir = null)
    {
    }

    public function name(): string
    {
        return 'capture';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(MailMessage $message): void
    {
        self::$sent[] = $message;
        if ($this->dir !== null && is_dir($this->dir)) {
            $file = $this->dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
            file_put_contents($file, "To: {$message->to}\nSubject: {$message->subject}\n\n{$message->text}\n", LOCK_EX);
        }
    }
}
