<?php

declare(strict_types=1);

namespace Concursei\Modules\Mail;

use Concursei\Core\Config;
use Concursei\Core\Logging\Logger;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** SMTP real com TLS via PHPMailer (biblioteca mantida). */
final class SmtpTransport implements Transport
{
    public function __construct(private Config $config, private Logger $logger)
    {
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function isAvailable(): bool
    {
        return $this->config->string('mail.host') !== ''
            && filter_var($this->config->string('mail.from_address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(MailMessage $message): void
    {
        if (!$this->isAvailable()) {
            throw new MailException('SMTP não configurado.');
        }
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->config->string('mail.host');
            $mail->Port = $this->config->int('mail.port', 587);
            $mail->SMTPSecure = $this->config->string('mail.encryption') === 'smtps' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth = $this->config->string('mail.username') !== '';
            $mail->Username = $this->config->string('mail.username');
            $mail->Password = $this->config->string('mail.password');
            $mail->Timeout = $this->config->int('mail.timeout', 10);
            $mail->SMTPDebug = 0;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->setFrom($this->config->string('mail.from_address'), $this->config->string('mail.from_name', 'Concursei'));
            $mail->addAddress($message->to);
            $mail->Subject = $message->subject;
            $mail->isHTML(true);
            $mail->Body = $message->html;
            $mail->AltBody = $message->text;
            if ($message->messageKey !== '') {
                $host = parse_url($this->config->string('app.url'), PHP_URL_HOST) ?: 'concursei.invalid';
                $mail->MessageID = '<' . hash('sha256', $message->messageKey) . '@' . $host . '>';
            }
            $mail->send();
        } catch (PHPMailerException $e) {
            // ErrorInfo pode conter endereço; o Logger redige e-mails.
            $this->logger->warning('Falha SMTP', ['error' => $mail->ErrorInfo]);
            throw new MailException('Falha ao enviar pelo servidor SMTP.', 0, $e);
        }
    }
}
