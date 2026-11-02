<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Auth;

use Concursei\Modules\Auth\PasswordHasher;
use Concursei\Modules\Mail\CaptureTransport;
use Concursei\Modules\Mail\DisabledTransport;
use Concursei\Modules\Mail\Transport;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;

final class RegistrationTest extends IntegrationTestCase
{
    use InteractsWithAuth;

    protected function setUp(): void
    {
        parent::setUp();
        CaptureTransport::$sent = [];
    }

    /** @return array<string,string> */
    private function form(array $over = []): array
    {
        return $over + [
            'display_name' => 'Maria Estudante',
            'email' => 'Maria@Example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accept_terms' => '1',
            'age_18' => '1',
        ];
    }

    public function testRegisterCreatesUnverifiedUserRecordsConsentsAndSendsVerification(): void
    {
        $res = $this->post('/cadastro', $this->form());
        $this->assertRedirect($res, '/cadastro/verifique-email');

        $user = $this->db->one('SELECT * FROM users WHERE email = ?', ['maria@example.com']);
        self::assertNotNull($user, 'E-mail é normalizado para minúsculas');
        self::assertNull($user['email_verified_at']);
        self::assertSame('student', $user['role']);
        self::assertStringNotContainsString(self::PASSWORD, (string) $user['password_hash']);
        self::assertTrue($this->app->container->get(PasswordHasher::class)->verify(self::PASSWORD, (string) $user['password_hash']));
        if (defined('PASSWORD_ARGON2ID')) {
            self::assertStringStartsWith('$argon2id$', (string) $user['password_hash']);
        }

        $purposes = array_column($this->db->all('SELECT purpose, granted FROM consents WHERE user_id = ? ORDER BY purpose', [$user['id']]), 'granted', 'purpose');
        self::assertEquals(['age_18' => 1, 'marketing_email' => 0, 'privacy' => 1, 'terms' => 1], $purposes, 'Marketing é opt-in (desmarcado por padrão)');

        $mail = $this->lastMailTo('maria@example.com');
        self::assertSame('Confirme seu e-mail no Concursei', $mail->subject);
        self::assertStringContainsString('/confirmar-email?token=', $mail->text);
        $outbox = $this->db->one('SELECT status, body_text, body_html FROM email_outbox WHERE to_email = ?', ['maria@example.com']);
        self::assertSame('sent', $outbox['status']);
        self::assertEmpty($outbox['body_text'], 'Corpo (com token) é apagado do banco após o envio');
        self::assertEmpty($outbox['body_html']);
    }

    public function testRegisterWithExistingEmailRevealsNothingAndWarnsTheOwner(): void
    {
        $this->createUser('dona@example.com');
        $res = $this->post('/cadastro', $this->form(['email' => 'dona@example.com', 'display_name' => 'Outra Pessoa']));
        $this->assertRedirect($res, '/cadastro/verifique-email');
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
        $mail = $this->lastMailTo('dona@example.com');
        self::assertSame('Tentativa de cadastro com seu e-mail', $mail->subject);
        self::assertStringNotContainsString('Outra Pessoa', $mail->text, 'Nome digitado por terceiro não vai para a caixa do titular');
    }

    public function testRegisterRequiresTermsAndAgeAndKeepsSafeInput(): void
    {
        $res = $this->post('/cadastro', $this->form(['accept_terms' => '', 'age_18' => '']));
        $this->assertRedirect($res, '/cadastro');
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
        $page = $this->request('GET', '/cadastro')->body();
        self::assertStringContainsString('value="Maria@Example.com"', $page, 'E-mail digitado é preservado');
        self::assertStringNotContainsString(self::PASSWORD, $page, 'Senha nunca é devolvida ao formulário');
    }

    public function testRegisterRejectsWeakAndMismatchedPasswords(): void
    {
        $this->assertRedirect($this->post('/cadastro', $this->form(['password' => '1234567890', 'password_confirmation' => '1234567890'])), '/cadastro');  // repo-safety: allow (senha fraca proposital, da lista de bloqueio)
        $this->assertRedirect($this->post('/cadastro', $this->form(['password_confirmation' => 'outra frase qualquer'])), '/cadastro');
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
        self::assertSame([], CaptureTransport::$sent);
    }

    public function testRegistrationIsDisabledWithoutMailTransport(): void
    {
        $this->app->container->set(Transport::class, new DisabledTransport());
        self::assertStringContainsString('temporariamente indisponível', $this->request('GET', '/cadastro')->body());
        $this->assertRedirect($this->post('/cadastro', $this->form()), '/cadastro');
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testVerificationLinkGetDoesNotConsumeAndPostConsumesOnce(): void
    {
        $this->post('/cadastro', $this->form());
        $token = $this->tokenFrom($this->lastMailTo('maria@example.com'));

        $page = $this->request('GET', '/confirmar-email?token=' . $token);
        self::assertSame(200, $page->status());
        self::assertNull($this->db->scalar('SELECT email_verified_at FROM users'), 'GET (pré-visualização de link) não confirma');

        $this->assertRedirect($this->post('/confirmar-email', ['token' => $token]), '/entrar');
        self::assertNotNull($this->db->scalar('SELECT email_verified_at FROM users'));
        $this->assertRedirect($this->post('/confirmar-email', ['token' => $token]), '/cadastro/verifique-email');
    }

    public function testVerificationTokenExpires(): void
    {
        $this->post('/cadastro', $this->form());
        $token = $this->tokenFrom($this->lastMailTo('maria@example.com'));
        $this->clock->setNow(new \DateTimeImmutable('+25 hours'));
        $this->assertRedirect($this->post('/confirmar-email', ['token' => $token]), '/cadastro/verifique-email');
        self::assertNull($this->db->scalar('SELECT email_verified_at FROM users'));
    }

    public function testResendIsGenericAndQuotaLimited(): void
    {
        $this->post('/cadastro', $this->form());
        $this->assertRedirect($this->post('/cadastro/reenviar', ['email' => 'maria@example.com']), '/cadastro/verifique-email');
        $this->assertRedirect($this->post('/cadastro/reenviar', ['email' => 'ninguem@example.com']), '/cadastro/verifique-email');
        self::assertSame([], $this->mailsTo('ninguem@example.com'));
        for ($i = 0; $i < 5; $i++) {
            $this->post('/cadastro/reenviar', ['email' => 'maria@example.com'], ['REMOTE_ADDR' => '198.51.100.' . $i]);
        }
        self::assertCount(3, $this->mailsTo('maria@example.com'), 'No máximo 3 e-mails por hora para o mesmo endereço');
    }
}
