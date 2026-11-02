<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Catalog;

use Concursei\Core\Http\Response;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use DateTimeImmutable;

final class AdminTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->setNow(new DateTimeImmutable('2027-02-15 12:00:00'));
    }

    public function testAdminRequiresStaffRoleAndMfa(): void
    {
        $this->assertRedirect($this->request('GET', '/admin'), '/entrar');

        $this->loginAs($this->createUser('aluna@example.com'));
        self::assertSame(403, $this->request('GET', '/admin/concursos')->status());
        self::assertSame(403, $this->post('/admin/concursos', ['title' => 'x'])->status());

        $this->session()->invalidate();
        $this->loginAs($this->createUser('semmfa@example.com', 'editor'));
        $this->assertRedirect($this->request('GET', '/admin'), '/conta/seguranca');

        $this->session()->invalidate();
        $this->loginAs($this->staff()->id);
        $res = $this->request('GET', '/admin');
        self::assertSame(200, $res->status());
        self::assertSame('private, no-store', $res->getHeader('Cache-Control'));
        self::assertStringContainsString('Aguardando revisão', $res->body());
    }

    public function testEditorBuildsContestThroughForms(): void
    {
        $this->loginAs($this->staff()->id);
        $this->assertRedirect($this->post('/admin/orgaos', ['name' => 'Tribunal Regional Exemplo', 'acronym' => 'TRE-X', 'sphere' => 'federal', 'uf' => 'RJ']), '/admin/orgaos');
        $orgId = (int) $this->db->scalar("SELECT id FROM organizations WHERE slug = 'tribunal-regional-exemplo'");
        $this->assertRedirect($this->post('/admin/cargos', ['name' => 'Analista Exemplo', 'area' => 'tribunais', 'education_level' => 'superior']), '/admin/cargos');
        $careerId = (int) $this->db->scalar("SELECT id FROM careers WHERE slug = 'analista-exemplo'");

        $res = $this->post('/admin/concursos', [
            'title' => 'TRE-X 2027', 'organization_id' => (string) $orgId, 'status' => 'edital_published', 'uf' => 'RJ',
            'fee' => '1.234,5', 'source_url' => 'https://example.com/edital.pdf', 'summary' => '<b>sem html</b>',
        ]);
        $id = (int) $this->db->scalar("SELECT id FROM contests WHERE slug = 'tre-x-2027'");
        $this->assertRedirect($res, '/admin/concursos/' . $id);
        self::assertSame(123450, $this->db->scalar('SELECT fee_cents FROM contests WHERE id = ?', [$id]));

        $this->assertRedirect($this->post("/admin/concursos/{$id}/cargos", [
            'version' => '1', 'title' => 'Analista Judiciário', 'career_id' => (string) $careerId, 'education_level' => 'superior',
            'vacancies' => '', 'reserve' => '1', 'salary' => '13.500,00',
        ]), "/admin/concursos/{$id}");
        $offer = $this->db->one('SELECT vacancies, reserve, salary_cents FROM contest_offers WHERE contest_id = ?', [$id]);
        self::assertSame(['vacancies' => null, 'reserve' => 1, 'salary_cents' => 1350000], $offer, 'Vagas não informadas ficam NULL');

        $this->assertRedirect($this->post("/admin/concursos/{$id}/datas", ['version' => '2', 'type' => 'exam', 'date' => '05/2027', 'time' => '13:00']), "/admin/concursos/{$id}/datas/nova");
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM contest_events WHERE contest_id = ?', [$id]), 'Horário sem dia exato é recusado');
        $this->assertRedirect($this->post("/admin/concursos/{$id}/datas", ['version' => '2', 'type' => 'exam', 'date' => '05/2027']), "/admin/concursos/{$id}");
        $this->assertRedirect($this->post("/admin/concursos/{$id}/datas", ['version' => '3', 'type' => 'registration_start', 'date' => '01/03/2027', 'time' => '10:00']), "/admin/concursos/{$id}");
        $events = $this->db->all('SELECT type, date_value, date_precision, time_local FROM contest_events WHERE contest_id = ? ORDER BY id', [$id]);
        self::assertSame([
            ['type' => 'exam', 'date_value' => '2027-05-01', 'date_precision' => 'month', 'time_local' => null],
            ['type' => 'registration_start', 'date_value' => '2027-03-01', 'date_precision' => 'day', 'time_local' => '10:00:00'],
        ], $events);

        $page = $this->request('GET', "/admin/concursos/{$id}")->body();
        self::assertStringContainsString('maio de 2027 (previsto)', $page);
        self::assertStringContainsString('Cadastro de reserva', $page);
        self::assertStringContainsString('&lt;b&gt;sem html&lt;/b&gt;', $page);
    }

    public function testStaleFormGetsConflictAndKeepsNewerData(): void
    {
        $editor = $this->staff();
        $this->loginAs($editor->id);
        $id = $this->contest($editor->id);
        $orgId = (string) $this->db->scalar('SELECT organization_id FROM contests WHERE id = ?', [$id]);
        $form = ['organization_id' => $orgId, 'status' => 'predicted', 'version' => '1'];

        $this->assertRedirect($this->post("/admin/concursos/{$id}", ['title' => 'Aba A'] + $form), "/admin/concursos/{$id}");
        $res = $this->post("/admin/concursos/{$id}", ['title' => 'Aba B'] + $form);
        self::assertSame(409, $res->status());
        self::assertStringContainsString('Recarregue a página', $res->body());
        self::assertSame('Aba A', $this->db->scalar('SELECT title FROM contests WHERE id = ?', [$id]));
    }

    public function testItemsAreScopedToTheirContest(): void
    {
        $editor = $this->staff();
        $this->loginAs($editor->id);
        $a = $this->contest($editor->id, [], 'concurso-a');
        $b = $this->contest($editor->id, [], 'concurso-b');
        $this->offer($b, $editor->id);
        $offerB = (int) $this->db->scalar('SELECT id FROM contest_offers WHERE contest_id = ?', [$b]);

        self::assertSame(404, $this->request('GET', "/admin/concursos/{$a}/cargos/{$offerB}")->status());
        self::assertSame(404, $this->delete("/admin/concursos/{$a}/cargos/{$offerB}", ['version' => (string) $this->version($a)])->status());
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM contest_offers WHERE id = ?', [$offerB]));
        self::assertSame(404, $this->request('GET', '/admin/concursos/999999')->status());
        self::assertSame(404, $this->request('GET', '/admin/usuarios')->status());
    }

    public function testPublishFromScreenNeedsVerificationCheckbox(): void
    {
        $editor = $this->staff();
        $this->loginAs($editor->id);
        $id = $this->contest($editor->id);
        $this->offer($id, $editor->id);

        $this->assertRedirect($this->post("/admin/concursos/{$id}/revisao", ['version' => (string) $this->version($id)]), "/admin/concursos/{$id}");
        self::assertSame('in_review', $this->db->scalar('SELECT workflow_status FROM contests WHERE id = ?', [$id]));

        $this->post("/admin/concursos/{$id}/publicar", ['version' => (string) $this->version($id), 'change_note' => 'Publicação inicial']);
        self::assertNull($this->db->scalar('SELECT published_revision_id FROM contests WHERE id = ?', [$id]));
        self::assertStringContainsString('Confirme que você conferiu', $this->request('GET', "/admin/concursos/{$id}")->body());

        $this->post("/admin/concursos/{$id}/publicar", ['version' => (string) $this->version($id), 'change_note' => 'Publicação inicial', 'verified' => '1']);
        self::assertNotNull($this->db->scalar('SELECT published_revision_id FROM contests WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM contest_search WHERE contest_id = ?', [$id]));

        $this->assertRedirect($this->delete("/admin/concursos/{$id}", ['version' => (string) $this->version($id)]), "/admin/concursos/{$id}");
        self::assertNotNull($this->db->scalar('SELECT id FROM contests WHERE id = ?', [$id]), 'Publicado não é excluído');
    }

    public function testStudentCannotUseWorkflowEndpointsEvenWithValidIds(): void
    {
        $editor = $this->staff();
        $id = $this->contest($editor->id);
        $this->loginAs($this->createUser('aluna@example.com'));
        foreach (['revisao', 'publicar', 'arquivar', 'devolver'] as $action) {
            self::assertSame(403, $this->post("/admin/concursos/{$id}/{$action}", ['version' => '1', 'verified' => '1', 'change_note' => 'tentativa'])->status(), $action);
        }
        self::assertSame('draft', $this->db->scalar('SELECT workflow_status FROM contests WHERE id = ?', [$id]));
    }

    public function testDuplicateSlugIsAValidationError(): void
    {
        $editor = $this->staff();
        $this->loginAs($editor->id);
        $this->contest($editor->id, [], 'ja-existe');
        $orgId = (string) $this->db->scalar('SELECT id FROM organizations LIMIT 1');
        $this->assertRedirect($this->post('/admin/concursos', ['title' => 'Outro', 'slug' => 'ja-existe', 'organization_id' => $orgId, 'status' => 'predicted']), '/admin/concursos/novo');
        self::assertStringContainsString('Esse endereço já está em uso', $this->request('GET', '/admin/concursos/novo')->body());
    }

    /** @param array<string,mixed> $post */
    private function delete(string $path, array $post = []): Response
    {
        $this->session()->start();
        $post['_csrf'] = $this->session()->csrfToken();
        return $this->request('DELETE', $path, $post);
    }
}
