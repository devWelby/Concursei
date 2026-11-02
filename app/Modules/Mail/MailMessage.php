<?php

declare(strict_types=1);

namespace Concursei\Modules\Mail;

final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly string $messageKey = '',
    ) {
    }
}
