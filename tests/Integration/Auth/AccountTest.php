<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Auth;

use Concursei\Modules\Mail\CaptureTransport;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;

final class AccountTest extends IntegrationTestCase
{
    use InteractsWithAuth;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        CaptureTransport::$sent = [];
        $this->userId = $this->createUser();
        $this->loginAs($this->userId);
    }

    public function testAccountPageIsPrivateAndEscapesName(): void
    {
        $this->db->run('UPDATE users SET display_name = ? WHERE id = ?', ['<script>alert(1)</script>', $this->userId]);
        $res = $this->request('GET', '/conta');
        self::assertSame(200, $res->status());
        self::assertSame('private, no-store', $res->getHeader('Cache-Control'));
        self::assertSame('noindex', $res->getHeader('X-Robots-Tag'));
        self::assertStringNotContainsString('<script>alert(1)</script>', $res->body());
        self::assertStringContainsString('&lt;script&gt;', $res->body());
    }

    public function testUpdateProfileNormalizesNameAndValidatesTimezone(): void
    {
        $this->assertRedirect($this->post('/conta/perfil', ['display_name' => "  Ana \n  Maria ", 'timezone' => 'America/Manaus']), '/conta');
        $row = $this->db->one('SELECT display_name, timezone FROM users WHERE id = ?', [$this->userId]);
        self::assertSame(['display_name' => 'Ana Maria', 'timezone' => 'America/Manaus'], $row);

        $this->assertRedirect($this->post('/conta/perfil', ['display_name' => 'Ana', 'timezone' => 'Europe/Lisbon']), '/conta');
        self::assertSame('America/Manaus', $this->db->scalar('SELECT timezone FROM users WHERE id = ?', [$this->userId]));
    }

    public function testChangePasswordRequiresCurrentAndRevokesOtherSessions(): void
    {
        $now = $this->clock->nowDb();
        $this->db->run('INSERT INTO sessions (id_hash, user_id, data, created_at, last_activity_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)', [
            random_bytes(32), $this->userId, '', $now, $now, $this->clock->plusSecondsDb(3600),
        ]);
        $new = 'nova frase de senha longa';
        $this->assertRedirect($this->post('/conta/senha', ['current_password' => 'errada errada', 'password' => $new, 'password_confirmation' => $new]), '/conta');
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$this->userId]));
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.reauth_failed'"));

        $this->assertRedirect($this->post('/conta/senha', ['current_password' => self::PASSWORD, 'password' => $new, 'password_confirmation' => $new]), '/conta');
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$this->userId]), 'Sessões de outros aparelhos encerradas');
        self::assertSame($this->userId, $this->session()->userId(), 'Sessão atual continua');
        self::assertSame('Sua senha do Concursei foi alterada', $this->lastMailTo('aluna@example.com')->subject);
    }

    public function testEmailChangeNeedsPasswordAndConfirmationAndNotifiesOldAddress(): void
    {
        $this->assertRedirect($this->post('/conta/email', ['new_email' => 'nova@example.com', 'current_password' => 'errada errada']), '/conta');
        self::assertSame([], $this->mailsTo('nova@example.com'));

        $this->assertRedirect($this->post('/conta/email', ['new_email' => 'Nova@Example.com', 'current_password' => self::PASSWORD]), '/conta');
        self::assertSame('aluna@example.com', $this->db->scalar('SELECT email FROM users WHERE id = ?', [$this->userId]), 'Nada muda antes da confirmação');
        $token = $this->tokenFrom($this->lastMailTo('nova@example.com'));

        $this->assertRedirect($this->post('/conta/email/confirmar', ['token' => $token]), '/conta');
        self::assertSame('nova@example.com', $this->db->scalar('SELECT email FROM users WHERE id = ?', [$this->userId]));
        self::assertSame('O e-mail da sua conta Concursei foi alterado', $this->lastMailTo('aluna@example.com')->subject);
        $this->assertRedirect($this->post('/conta/email/confirmar', ['token' => $token]), '/conta');
        self::assertSame('nova@example.com', $this->db->scalar('SELECT email FROM users WHERE id = ?', [$this->userId]));
    }

    public function testEmailChangeToTakenAddressLooksLikeSuccessButSendsNothing(): void
    {
        $this->createUser('ocupado@example.com');
        $taken = $this->post('/conta/email', ['new_email' => 'ocupado@example.com', 'current_password' => self::PASSWORD]);
        $this->assertRedirect($taken, '/conta');
        self::assertStringContainsString('Se o endereço puder ser usado', $this->request('GET', '/conta')->body());
        self::assertSame([], $this->mailsTo('ocupado@example.com'));
    }

    public function testMarketingConsentCanBeWithdrawnAndIsAudited(): void
    {
        $this->post('/conta/preferencias', ['marketing' => '1']);
        $this->post('/conta/preferencias', []);
        $rows = $this->db->all("SELECT granted, source FROM consents WHERE user_id = ? AND purpose = 'marketing_email' ORDER BY id", [$this->userId]);
        self::assertSame([['granted' => 1, 'source' => 'account'], ['granted' => 0, 'source' => 'account']], array_map(static fn ($r) => ['granted' => (int) $r['granted'], 'source' => $r['source']], $rows));
        $this->post('/conta/preferencias', []);
        self::assertCount(2, $this->db->all("SELECT id FROM consents WHERE user_id = ? AND purpose = 'marketing_email'", [$this->userId]), 'Sem registro duplicado quando nada muda');
    }
}
