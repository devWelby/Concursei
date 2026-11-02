<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Auth;

use Concursei\Modules\Auth\MfaService;
use Concursei\Modules\Mail\CaptureTransport;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use DateTimeImmutable;
use OTPHP\TOTP;

final class MfaTest extends IntegrationTestCase
{
    use InteractsWithAuth;

    private int $userId;
    private DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        CaptureTransport::$sent = [];
        $this->t0 = new DateTimeImmutable('2026-11-02 12:00:00');
        $this->clock->setNow($this->t0);
        $this->userId = $this->createUser();
    }

    private function codeAt(string $secret, int $seconds = 0): string
    {
        return TOTP::createFromSecret($secret)->at($this->t0->getTimestamp() + $seconds);
    }

    /** Ativa pelo fluxo HTTP real e devolve [segredo, códigos de recuperação]. @return array{0:string,1:list<string>} */
    private function enableViaHttp(): array
    {
        $this->loginAs($this->userId);
        $page = $this->request('GET', '/conta/seguranca')->body();
        self::assertSame(1, preg_match('/class="secret-key"[^>]*>([^<]+)</', $page, $m));
        $secret = str_replace(' ', '', $m[1]);
        self::assertSame(32, strlen($secret), 'Chave de 160 bits (32 caracteres base32)');

        $res = $this->post('/conta/seguranca/ativar', ['current_password' => self::PASSWORD, 'code' => $this->codeAt($secret)]);
        self::assertSame(200, $res->status());
        self::assertSame('private, no-store', $res->getHeader('Cache-Control'));
        preg_match_all('#<li><code>([a-z0-9]{5}-[a-z0-9]{5})</code></li>#', $res->body(), $codes);
        self::assertCount(10, $codes[1]);
        return [$secret, $codes[1]];
    }

    private function loginPassword(): void
    {
        $this->post('/sair');
        $this->assertRedirect($this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD]), '/entrar/verificacao');
    }

    public function testEnableStoresEncryptedSecretAndNotifies(): void
    {
        [$secret] = $this->enableViaHttp();
        $row = $this->db->one('SELECT mfa_secret_enc, mfa_enabled_at FROM users WHERE id = ?', [$this->userId]);
        self::assertNotNull($row['mfa_enabled_at']);
        self::assertStringNotContainsString($secret, (string) $row['mfa_secret_enc']);
        self::assertSame(10, (int) $this->db->scalar('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ?', [$this->userId]));
        self::assertSame('Verificação em duas etapas alterada na sua conta', $this->lastMailTo('aluna@example.com')->subject);
    }

    public function testEnableFailsWithWrongCodeOrPassword(): void
    {
        $this->loginAs($this->userId);
        $page = $this->request('GET', '/conta/seguranca')->body();
        preg_match('/class="secret-key"[^>]*>([^<]+)</', $page, $m);
        $secret = str_replace(' ', '', $m[1]);
        $this->assertRedirect($this->post('/conta/seguranca/ativar', ['current_password' => self::PASSWORD, 'code' => '000000']), '/conta/seguranca');
        $this->assertRedirect($this->post('/conta/seguranca/ativar', ['current_password' => 'errada errada', 'code' => $this->codeAt($secret)]), '/conta/seguranca');
        self::assertNull($this->db->scalar('SELECT mfa_enabled_at FROM users WHERE id = ?', [$this->userId]));
        // A chave pendente é a mesma entre recargas (fica cifrada na sessão).
        self::assertStringContainsString(trim(chunk_split($secret, 4, ' ')), $this->request('GET', '/conta/seguranca')->body());
    }

    public function testLoginRequiresSecondStepAndRejectsReplay(): void
    {
        [$secret] = $this->enableViaHttp();
        $this->loginPassword();
        self::assertNull($this->session()->userId(), 'Senha correta sozinha não autentica');
        $this->assertRedirect($this->request('GET', '/conta'), '/entrar');

        // Mesmo código usado na ativação (mesmo passo de 30 s) não pode ser reutilizado.
        $this->assertRedirect($this->post('/entrar/verificacao', ['code' => $this->codeAt($secret)]), '/entrar/verificacao');
        self::assertNull($this->session()->userId());

        $this->clock->setNow($this->t0->modify('+30 seconds'));
        $this->assertRedirect($this->post('/entrar/verificacao', ['code' => $this->codeAt($secret, 30)]), '/conta');
        self::assertSame($this->userId, $this->session()->userId());
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login' AND meta LIKE '%\"mfa\":true%'"));
    }

    public function testRecoveryCodeWorksOnce(): void
    {
        [, $codes] = $this->enableViaHttp();
        $this->loginPassword();
        $this->assertRedirect($this->post('/entrar/verificacao', ['code' => $codes[0]]), '/conta');
        $this->loginPassword();
        $this->assertRedirect($this->post('/entrar/verificacao', ['code' => $codes[0]]), '/entrar/verificacao');
        self::assertSame(9, $this->app->container->get(MfaService::class)->remainingRecoveryCodes($this->userId));
    }

    public function testSecondStepExpiresAndLimitsAttempts(): void
    {
        [$secret] = $this->enableViaHttp();
        $this->loginPassword();
        for ($i = 0; $i < 4; $i++) {
            $this->assertRedirect($this->post('/entrar/verificacao', ['code' => '000000']), '/entrar/verificacao');
        }
        $this->assertRedirect($this->post('/entrar/verificacao', ['code' => '000000']), '/entrar');
        $this->assertRedirect($this->request('GET', '/entrar/verificacao'), '/entrar');

        $this->loginPassword();
        $this->clock->setNow($this->t0->modify('+301 seconds'));
        $this->assertRedirect($this->post('/entrar/verificacao', ['code' => $this->codeAt($secret, 301)]), '/entrar');
        self::assertNull($this->session()->userId());
    }

    public function testPerAccountLockoutBlocksEvenCorrectCodes(): void
    {
        [$secret] = $this->enableViaHttp();
        $mfa = $this->app->container->get(MfaService::class);
        for ($i = 0; $i < MfaService::MAX_FAILURES; $i++) {
            self::assertFalse($mfa->verifyForUser($this->userId, '000000'));
        }
        self::assertTrue($mfa->lockedOut($this->userId));
        $this->loginPassword();
        $this->clock->setNow($this->t0->modify('+60 seconds'));
        $this->assertRedirect($this->post('/entrar/verificacao', ['code' => $this->codeAt($secret, 60)]), '/entrar');
        self::assertNull($this->session()->userId());

        $this->clock->setNow($this->t0->modify('+16 minutes'));
        self::assertFalse($mfa->lockedOut($this->userId), 'Bloqueio expira após a janela');
    }

    public function testDisableAndRegenerateRequirePasswordAndCode(): void
    {
        [$secret, $codes] = $this->enableViaHttp();
        $this->clock->setNow($this->t0->modify('+30 seconds'));
        $this->assertRedirect($this->post('/conta/seguranca/codigos', ['current_password' => 'errada errada', 'code' => $this->codeAt($secret, 30)]), '/conta/seguranca');
        $res = $this->post('/conta/seguranca/codigos', ['current_password' => self::PASSWORD, 'code' => $this->codeAt($secret, 30)]);
        self::assertSame(200, $res->status());
        preg_match_all('#<li><code>([a-z0-9]{5}-[a-z0-9]{5})</code></li>#', $res->body(), $new);
        self::assertCount(10, $new[1]);
        self::assertSame([], array_intersect($codes, $new[1]));

        $this->assertRedirect($this->post('/conta/seguranca/desativar', ['current_password' => self::PASSWORD, 'code' => $codes[0]]), '/conta/seguranca');
        self::assertNotNull($this->db->scalar('SELECT mfa_enabled_at FROM users WHERE id = ?', [$this->userId]), 'Código antigo de recuperação não vale mais');

        $this->assertRedirect($this->post('/conta/seguranca/desativar', ['current_password' => self::PASSWORD, 'code' => $new[1][0]]), '/conta/seguranca');
        self::assertNull($this->db->scalar('SELECT mfa_enabled_at FROM users WHERE id = ?', [$this->userId]));
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ?', [$this->userId]));
    }
}
