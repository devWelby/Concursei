<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Auth;

use Concursei\Modules\Auth\AccountDataService;
use Concursei\Modules\Auth\AuthService;
use Concursei\Modules\Auth\TokenService;
use Concursei\Shared\Privacy\ConsentService;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use DateTimeImmutable;

final class AccountDataTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    private int $student;
    private int $contestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->setNow(new DateTimeImmutable('2027-02-15 12:00:00'));
        $this->contestId = $this->publishedContest($this->staff());
        $this->student = $this->createUser('aluna@example.com');
        $this->db->run('INSERT INTO favorites (user_id, contest_id, created_at) VALUES (?, ?, ?)', [$this->student, $this->contestId, $this->clock->nowDb()]);
        $consents = $this->app->container->get(ConsentService::class);
        $consents->record($this->student, null, 'terms', '2026-11-02', true, 'register', '203.0.113.10');
        $consents->record($this->student, null, 'marketing_email', '2026-11-02', true, 'register', '203.0.113.10');
        $this->app->container->get(TokenService::class)->issue($this->student, 'password_reset', 3600);
    }

    public function testExportRequiresPasswordAndContainsOnlyTheOwnersData(): void
    {
        $other = $this->createUser('outra@example.com');
        $this->db->run('INSERT INTO favorites (user_id, contest_id, created_at) VALUES (?, ?, ?)', [$other, $this->contestId, $this->clock->nowDb()]);
        $this->loginAs($this->student);
        self::assertSame(200, $this->request('GET', '/conta/dados')->status());

        $this->assertRedirect($this->post('/conta/dados/exportar', ['password' => self::PASSWORD . '-errada']), '/conta/dados');
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'account.data_bad_password'"));

        $res = $this->post('/conta/dados/exportar', ['password' => self::PASSWORD]);
        self::assertSame(200, $res->status());
        self::assertSame('attachment; filename="concursei-meus-dados-2027-02-15.json"', $res->getHeader('Content-Disposition'));
        self::assertSame('private, no-store', $res->getHeader('Cache-Control'));
        $body = $res->body();
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(AccountDataService::FORMAT, $data['formato']);
        self::assertSame('aluna@example.com', $data['conta']['email']);
        self::assertCount(1, $data['concursos_acompanhados'], 'Só os favoritos do titular');
        self::assertSame('concurso-exemplo-2027', $data['concursos_acompanhados'][0]['endereco']);
        self::assertSame(['terms', 'marketing_email'], array_column($data['aceites'], 'finalidade'));
        self::assertStringNotContainsString('outra@example.com', $body);
        $hash = (string) $this->db->scalar('SELECT password_hash FROM users WHERE id = ?', [$this->student]);
        self::assertStringNotContainsString($hash, $body, 'Hash de senha nunca sai na exportação');
        self::assertStringNotContainsString('password_hash', $body);
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'account.export'"));
    }

    public function testDeletionAnonymizesAndRemovesPersonalData(): void
    {
        $this->loginAs($this->student);
        $this->assertRedirect($this->post('/conta/excluir', ['delete_password' => self::PASSWORD]), '/conta/dados');
        $this->assertRedirect($this->post('/conta/excluir', ['delete_password' => self::PASSWORD . '-errada', 'confirm' => '1']), '/conta/dados');
        self::assertSame('active', $this->db->scalar('SELECT status FROM users WHERE id = ?', [$this->student]), 'Sem confirmação ou com senha errada, nada muda');

        $this->assertRedirect($this->post('/conta/excluir', ['delete_password' => self::PASSWORD, 'confirm' => '1']), '/');
        $row = $this->db->one('SELECT email, display_name, status, email_verified_at, deleted_at FROM users WHERE id = ?', [$this->student]);
        self::assertSame([AccountDataService::anonymousEmail($this->student), 'Conta excluída', 'deleted', null], [$row['email'], $row['display_name'], $row['status'], $row['email_verified_at']]);
        self::assertNotNull($row['deleted_at']);
        foreach (['sessions' => 'user_id', 'favorites' => 'user_id', 'auth_tokens' => 'user_id', 'mfa_recovery_codes' => 'user_id'] as $table => $col) {
            self::assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM {$table} WHERE {$col} = ?", [$this->student]), $table);
        }
        self::assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM consents WHERE user_id = ?', [$this->student]), 'Prova de aceite fica, ligada a uma conta anônima');
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'account.delete' AND target_id = ?", [$this->student]));

        $this->assertRedirect($this->request('GET', '/conta'), '/entrar');
        $auth = $this->app->container->get(AuthService::class);
        self::assertSame('invalid', $auth->attemptLogin('aluna@example.com', self::PASSWORD, '203.0.113.10')['status'], 'Login antigo não funciona');
        self::assertNotSame(0, $this->createUser('aluna@example.com'), 'O e-mail fica livre para um cadastro novo');
        self::assertFalse($this->app->container->get(AccountDataService::class)->delete($this->student), 'Exclusão repetida não faz nada');
    }

    public function testStaffAccountsAreNotSelfDeleted(): void
    {
        $editor = $this->staff('outra-editora@example.com');
        $this->loginAs($editor->id);
        $this->assertRedirect($this->post('/conta/excluir', ['delete_password' => self::PASSWORD, 'confirm' => '1']), '/conta/dados');
        self::assertSame('active', $this->db->scalar('SELECT status FROM users WHERE id = ?', [$editor->id]));
    }
}
