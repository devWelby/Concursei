<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Auth;

use Concursei\Modules\Mail\CaptureTransport;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;

final class PasswordResetTest extends IntegrationTestCase
{
    use InteractsWithAuth;

    private const NEW_PASSWORD = 'outra frase bem comprida';

    protected function setUp(): void
    {
        parent::setUp();
        CaptureTransport::$sent = [];
    }

    private function fakeSession(int $userId): void
    {
        $now = $this->clock->nowDb();
        $this->db->run('INSERT INTO sessions (id_hash, user_id, data, created_at, last_activity_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)', [
            random_bytes(32), $userId, '', $now, $now, $this->clock->plusSecondsDb(3600),
        ]);
    }

    public function testFullResetFlowRevokesSessionsAndNotifies(): void
    {
        $id = $this->createUser();
        $this->fakeSession($id);
        $this->fakeSession($id);

        $this->assertRedirect($this->post('/recuperar-senha', ['email' => 'aluna@example.com']), '/recuperar-senha');
        $token = $this->tokenFrom($this->lastMailTo('aluna@example.com'));

        $form = $this->request('GET', '/redefinir-senha?token=' . $token);
        self::assertStringContainsString('name="token"', $form->body());

        $this->assertRedirect($this->post('/redefinir-senha', [
            'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ]), '/entrar');

        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$id]), 'Todas as sessões são encerradas');
        self::assertSame('Sua senha do Concursei foi alterada', $this->lastMailTo('aluna@example.com')->subject);

        $this->assertRedirect($this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD]), '/entrar');
        self::assertNull($this->session()->userId());
        $this->assertRedirect($this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::NEW_PASSWORD]), '/conta');

        // Token é de uso único.
        $this->post('/sair');
        $this->assertRedirect($this->post('/redefinir-senha', [
            'token' => $token, 'password' => 'mais uma frase longa', 'password_confirmation' => 'mais uma frase longa',
        ]), '/recuperar-senha');
    }

    public function testUnknownEmailGetsSameAnswerAndNoMail(): void
    {
        $this->createUser();
        $a = $this->post('/recuperar-senha', ['email' => 'ninguem@example.com']);
        $b = $this->post('/recuperar-senha', ['email' => 'aluna@example.com'], ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertSame($a->getHeader('Location'), $b->getHeader('Location'));
        self::assertSame([], $this->mailsTo('ninguem@example.com'));
        self::assertCount(1, $this->mailsTo('aluna@example.com'));
    }

    public function testResetTokenExpiresAfterOneHour(): void
    {
        $this->createUser();
        $this->post('/recuperar-senha', ['email' => 'aluna@example.com']);
        $token = $this->tokenFrom($this->lastMailTo('aluna@example.com'));
        $this->clock->setNow(new \DateTimeImmutable('+61 minutes'));
        self::assertStringContainsString('inválido, já foi usado ou expirou', $this->request('GET', '/redefinir-senha?token=' . $token)->body());
    }

    public function testNewRequestInvalidatesPreviousLink(): void
    {
        $this->createUser();
        $this->post('/recuperar-senha', ['email' => 'aluna@example.com']);
        $first = $this->tokenFrom($this->lastMailTo('aluna@example.com'));
        $this->post('/recuperar-senha', ['email' => 'aluna@example.com']);
        $second = $this->tokenFrom($this->lastMailTo('aluna@example.com'));
        self::assertNotSame($first, $second);
        self::assertStringContainsString('expirou', $this->request('GET', '/redefinir-senha?token=' . $first)->body());
        self::assertStringContainsString('name="token"', $this->request('GET', '/redefinir-senha?token=' . $second)->body());
    }

    public function testMalformedTokenIsRejectedWithoutError(): void
    {
        $res = $this->request('GET', '/redefinir-senha?token=' . rawurlencode("' OR 1=1 --"));
        self::assertSame(200, $res->status());
        self::assertStringContainsString('expirou', $res->body());
    }
}
