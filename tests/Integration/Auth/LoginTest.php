<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Auth;

use Concursei\Modules\Home\HomeController;
use Concursei\Modules\Mail\CaptureTransport;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;

final class LoginTest extends IntegrationTestCase
{
    use InteractsWithAuth;

    protected function setUp(): void
    {
        parent::setUp();
        CaptureTransport::$sent = [];
    }

    public function testLoginSucceedsRegeneratesCsrfAndRecordsAudit(): void
    {
        $id = $this->createUser();
        $this->session()->start();
        $before = $this->session()->csrfToken();
        $this->assertRedirect($this->post('/entrar', ['email' => 'ALUNA@example.com', 'password' => self::PASSWORD]), '/painel');
        self::assertSame($id, $this->session()->userId());
        self::assertNotSame($before, $this->session()->csrfToken(), 'Estado da sessão é renovado no login (fixação)');
        self::assertNotNull($this->db->scalar('SELECT last_login_at FROM users WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login'"));
        self::assertSame(200, $this->request('GET', '/conta')->status());
    }

    public function testWrongPasswordAndUnknownEmailGetTheSameAnswer(): void
    {
        $this->createUser();
        $a = $this->post('/entrar', ['email' => 'aluna@example.com', 'password' => 'senha errada demais']);
        $pageA = $this->request('GET', '/entrar')->body();
        $b = $this->post('/entrar', ['email' => 'ninguem@example.com', 'password' => 'senha errada demais']);
        $pageB = $this->request('GET', '/entrar')->body();
        self::assertSame($a->getHeader('Location'), $b->getHeader('Location'));
        self::assertStringContainsString('E-mail ou senha incorretos.', $pageA);
        self::assertStringContainsString('E-mail ou senha incorretos.', $pageB);
        self::assertNull($this->session()->userId());
    }

    public function testUnverifiedAccountCannotLogIn(): void
    {
        $this->createUser(verified: false);
        $this->assertRedirect($this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD]), '/cadastro/verifique-email');
        self::assertNull($this->session()->userId());
    }

    public function testPerAccountThrottleHoldsAcrossDifferentIps(): void
    {
        $this->createUser();
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/entrar', ['email' => 'aluna@example.com', 'password' => 'tentativa errada ' . $i], ['REMOTE_ADDR' => '198.51.100.' . $i]);
        }
        $res = $this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD], ['REMOTE_ADDR' => '198.51.100.99']);
        self::assertSame(429, $res->status(), 'Senha correta também é bloqueada durante a janela');
        self::assertNotNull($res->getHeader('Retry-After'));
        self::assertNull($this->session()->userId());
    }

    public function testPerIpThrottleOnLoginRoute(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/entrar', ['email' => "pessoa{$i}@example.com", 'password' => 'qualquer coisa longa']);
        }
        self::assertSame(429, $this->post('/entrar', ['email' => 'outra@example.com', 'password' => 'qualquer coisa longa'])->status());
    }

    public function testLoginWithoutCsrfTokenIsRejected(): void
    {
        $this->createUser();
        $res = $this->request('POST', '/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD]);
        self::assertSame(403, $res->status());
        self::assertNull($this->session()->userId());
    }

    public function testCrossOriginPostIsRejected(): void
    {
        $this->createUser();
        $res = $this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD], ['HTTP_ORIGIN' => 'https://atacante.example.net']);
        self::assertSame(403, $res->status());
    }

    public function testIntendedLocalPathIsRestoredAfterLogin(): void
    {
        $this->createUser();
        $this->assertRedirect($this->request('GET', '/conta/seguranca?aba=mfa'), '/entrar');
        $this->assertRedirect($this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD]), '/conta/seguranca?aba=mfa');
    }

    public function testGuestPagesRedirectWhenLoggedIn(): void
    {
        $this->loginAs($this->createUser());
        $this->assertRedirect($this->request('GET', '/entrar'), '/painel');
        $this->assertRedirect($this->request('GET', '/cadastro'), '/painel');
    }

    public function testLogoutDestroysSession(): void
    {
        $this->loginAs($this->createUser());
        $this->assertRedirect($this->post('/sair'), '/');
        self::assertNull($this->session()->userId());
        $this->assertRedirect($this->request('GET', '/conta'), '/entrar');
    }

    public function testSuspendedUserLosesSessionImmediately(): void
    {
        $id = $this->createUser();
        $this->loginAs($id);
        $this->db->run("UPDATE users SET status = 'suspended' WHERE id = ?", [$id]);
        $this->assertRedirect($this->request('GET', '/conta'), '/entrar');
        self::assertNull($this->session()->userId());
        $this->assertRedirect($this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD]), '/entrar');
    }

    public function testProtectedPostWithoutLoginIs401(): void
    {
        self::assertSame(401, $this->post('/conta/perfil', ['display_name' => 'X', 'timezone' => 'America/Sao_Paulo'])->status());
    }

    public function testAdminAreaRequiresPermissionAndStaffMfa(): void
    {
        $this->app->router()->get('/admin/teste', [HomeController::class, 'health'], ['middleware' => ['auth', 'can:admin.access']]);

        $this->assertRedirect($this->request('GET', '/admin/teste'), '/entrar');

        $this->loginAs($this->createUser('aluna2@example.com'));
        self::assertSame(403, $this->request('GET', '/admin/teste')->status(), 'Estudante não acessa a administração');

        $editor = $this->createUser('editora@example.com', 'editor');
        $this->loginAs($editor);
        $this->assertRedirect($this->request('GET', '/admin/teste'), '/conta/seguranca');

        $this->db->run('UPDATE users SET mfa_secret_enc = ?, mfa_enabled_at = ? WHERE id = ?', ['x', $this->clock->nowDb(), $editor]);
        self::assertSame(200, $this->request('GET', '/admin/teste')->status());
    }
}
