<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Catalog;

use Concursei\Core\Http\Response;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use DateTimeImmutable;

final class FavoritesTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    private int $contestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:00:00'));
        $this->contestId = $this->publishedContest($this->staff());
        $this->session()->invalidate();
    }

    public function testAnonymousFollowGoesThroughLoginAndBackToContest(): void
    {
        $this->assertRedirect($this->request('GET', '/concursos/concurso-exemplo-2027/acompanhar'), '/entrar');
        $this->createUser();
        $this->assertRedirect($this->post('/entrar', ['email' => 'aluna@example.com', 'password' => self::PASSWORD]), '/concursos/concurso-exemplo-2027/acompanhar');
        $this->assertRedirect($this->request('GET', '/concursos/concurso-exemplo-2027/acompanhar'), '/concursos/concurso-exemplo-2027#acompanhar');
        $page = $this->request('GET', '/concursos/concurso-exemplo-2027');
        self::assertSame('private, no-store', $page->getHeader('Cache-Control'), 'Página com sessão nunca é cacheada publicamente');
        self::assertStringContainsString('action="/favoritos"', $page->body());
    }

    public function testFollowIsIdempotentAndOnlyForPublishedContests(): void
    {
        $userId = $this->createUser();
        $this->loginAs($userId);
        $this->assertRedirect($this->post('/favoritos', ['contest_id' => (string) $this->contestId]), '/concursos/concurso-exemplo-2027#acompanhar');
        $this->assertRedirect($this->post('/favoritos', ['contest_id' => (string) $this->contestId]), '/concursos/concurso-exemplo-2027#acompanhar');
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$userId]));
        self::assertStringContainsString('Deixar de acompanhar', $this->request('GET', '/concursos/concurso-exemplo-2027')->body());

        $draft = $this->contest((int) $this->db->scalar("SELECT id FROM users WHERE role = 'editor'"), [], 'rascunho-oculto');
        self::assertSame(404, $this->post('/favoritos', ['contest_id' => (string) $draft])->status());
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM favorites WHERE contest_id = ?', [$draft]));
        self::assertSame(403, $this->request('POST', '/favoritos', ['contest_id' => (string) $this->contestId])->status(), 'Sem token CSRF é recusado antes de qualquer efeito');
    }

    public function testCannotRemoveSomeoneElsesFavorite(): void
    {
        $owner = $this->createUser('dona@example.com');
        $this->db->run('INSERT INTO favorites (user_id, contest_id, created_at) VALUES (?, ?, ?)', [$owner, $this->contestId, $this->clock->nowDb()]);
        $favId = (int) $this->db->scalar('SELECT id FROM favorites WHERE user_id = ?', [$owner]);

        $this->loginAs($this->createUser('outra@example.com'));
        self::assertSame(404, $this->delete('/favoritos/' . $favId)->status());
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM favorites WHERE id = ?', [$favId]));
        self::assertStringNotContainsString('Concurso Exemplo 2027', $this->request('GET', '/painel')->body(), 'Painel mostra só os próprios favoritos');

        $this->session()->invalidate();
        $this->loginAs($owner);
        $this->assertRedirect($this->delete('/favoritos/' . $favId, ['voltar' => 'painel']), '/painel');
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM favorites WHERE id = ?', [$favId]));
    }

    public function testDashboardShowsCountdownsUpcomingDatesAndArchivedContests(): void
    {
        $this->assertRedirect($this->request('GET', '/painel'), '/entrar');

        $userId = $this->createUser();
        $this->loginAs($userId);
        self::assertStringContainsString('Você ainda não acompanha nenhum concurso', $this->request('GET', '/painel')->body());

        $this->post('/favoritos', ['contest_id' => (string) $this->contestId]);
        $res = $this->request('GET', '/painel');
        self::assertSame(200, $res->status());
        self::assertSame('private, no-store', $res->getHeader('Cache-Control'));
        $body = $res->body();
        self::assertStringContainsString('Inscrições até 31/03/2027 (em 21 dias)', $body);
        self::assertStringContainsString('Fim das inscrições — <a href="/concursos/concurso-exemplo-2027">', $body);
        self::assertStringNotContainsString('10/05/2027', $body, 'Prova a 61 dias fica fora da janela de 60 dias');

        $this->publication()->archive($this->contestId, $this->staff('admin@example.com', 'admin'));
        $body = $this->request('GET', '/painel')->body();
        self::assertStringContainsString('Retirado do catálogo', $body);
        self::assertStringContainsString('Concurso Exemplo 2027', $body, 'Título vem da última revisão publicada');
    }

    public function testFavoritesAreDeletedWithTheAccount(): void
    {
        $userId = $this->createUser();
        $this->db->run('INSERT INTO favorites (user_id, contest_id, created_at) VALUES (?, ?, ?)', [$userId, $this->contestId, $this->clock->nowDb()]);
        $this->db->run('DELETE FROM users WHERE id = ?', [$userId]);
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$userId]));
    }

    /** @param array<string,mixed> $post */
    private function delete(string $path, array $post = []): Response
    {
        $this->session()->start();
        $post['_csrf'] = $this->session()->csrfToken();
        return $this->request('DELETE', $path, $post);
    }
}
