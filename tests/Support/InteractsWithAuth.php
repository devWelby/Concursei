<?php

declare(strict_types=1);

namespace Concursei\Tests\Support;

use Concursei\Core\Session\Session;
use Concursei\Modules\Auth\PasswordHasher;
use Concursei\Modules\Auth\UserRepository;
use Concursei\Modules\Mail\CaptureTransport;
use Concursei\Modules\Mail\MailMessage;

/** Ajudantes de autenticação/e-mail para testes de integração. */
trait InteractsWithAuth
{
    protected const PASSWORD = 'uma frase longa e segura';

    protected function createUser(string $email = 'aluna@example.com', string $role = 'student', bool $verified = true, string $password = self::PASSWORD): int
    {
        return $this->app->container->get(UserRepository::class)->create(
            $email,
            $this->app->container->get(PasswordHasher::class)->hash($password),
            'Pessoa Teste',
            $role,
            $verified,
        );
    }

    protected function loginAs(int $userId): void
    {
        $session = $this->app->container->get(Session::class);
        $session->start();
        $session->login($userId);
    }

    protected function session(): Session
    {
        return $this->app->container->get(Session::class);
    }

    /** @return list<MailMessage> */
    protected function mailsTo(string $email): array
    {
        return array_values(array_filter(CaptureTransport::$sent, static fn (MailMessage $m): bool => $m->to === $email));
    }

    protected function lastMailTo(string $email): MailMessage
    {
        $mails = $this->mailsTo($email);
        self::assertNotEmpty($mails, "Nenhum e-mail capturado para {$email}");
        return $mails[count($mails) - 1];
    }

    protected function tokenFrom(MailMessage $mail): string
    {
        self::assertSame(1, preg_match('/[?&]token=([A-Za-z0-9_-]{43})/', $mail->text, $m), 'Link com token ausente no e-mail');
        return $m[1];
    }

    protected function assertRedirect(\Concursei\Core\Http\Response $res, string $path): void
    {
        self::assertContains($res->status(), [302, 303], 'Esperado redirecionamento; corpo: ' . substr($res->body(), 0, 300));
        self::assertSame($path, $res->getHeader('Location'));
    }
}
