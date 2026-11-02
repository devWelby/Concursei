<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Catalog;

use Concursei\Modules\Catalog\PublicCatalog;
use Concursei\Modules\Catalog\SearchIndex;
use Concursei\Modules\Catalog\StaleVersion;
use Concursei\Modules\Catalog\WorkflowViolation;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use DateTimeImmutable;

final class PublicationTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        // 15/02/2027 12:00 UTC: edital publicado, inscrições ainda não abertas.
        $this->clock->setNow(new DateTimeImmutable('2027-02-15 12:00:00'));
    }

    public function testPublishWritesImmutableRevisionAndPublicReadModel(): void
    {
        $editor = $this->staff();
        $id = $this->publishedContest($editor);

        $c = $this->db->one('SELECT workflow_status, published_revision_id, last_verified_at FROM contests WHERE id = ?', [$id]);
        self::assertSame('published', $c['workflow_status']);
        self::assertSame('2027-02-15 12:00:00', $c['last_verified_at']);

        $rev = $this->db->one('SELECT revision_no, is_correction, change_note, published_by FROM contest_revisions WHERE id = ?', [$c['published_revision_id']]);
        self::assertSame([1, 0, 'Publicação inicial conferida com o edital', $editor->id], [$rev['revision_no'], $rev['is_correction'], $rev['change_note'], $rev['published_by']]);

        $row = $this->db->one('SELECT * FROM contest_search WHERE contest_id = ?', [$id]);
        self::assertSame('edital_published', $row['phase']);
        self::assertSame('registration_start', $row['next_event_type']);
        self::assertSame('2027-03-01', $row['next_event_on']);
        self::assertSame('2027-03-31', $row['registration_ends_on']);
        self::assertSame('sudeste', $row['region']);
        self::assertSame(10, $row['vacancies_total']);
        self::assertSame(1, $row['has_reserve']);
        self::assertSame(1350000, $row['salary_max_cents']);
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM contest_search_offers WHERE contest_id = ? AND area = ?', [$id, 'tribunais']));
        self::assertSame(3, (int) $this->db->scalar('SELECT COUNT(*) FROM contest_search_events WHERE contest_id = ?', [$id]));
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'contest.published' AND target_id = ?", [$id]));
    }

    public function testEditingPublishedContestDoesNotChangePublicPageUntilRepublished(): void
    {
        $editor = $this->staff();
        $id = $this->publishedContest($editor);
        $data = $this->db->one('SELECT organization_id, exam_board_id, edition_year, uf, status, summary, fee_cents, fee_note, official_url, registration_url, source_url, source_published_on FROM contests WHERE id = ?', [$id]);

        $this->contests()->update($id, $this->version($id), 'outro-endereco', ['title' => 'Título corrigido'] + $data, $editor->id);
        self::assertSame('draft', $this->db->scalar('SELECT workflow_status FROM contests WHERE id = ?', [$id]));
        self::assertSame('concurso-exemplo-2027', $this->db->scalar('SELECT slug FROM contests WHERE id = ?', [$id]), 'Endereço não muda depois de publicado');

        $public = $this->app->container->get(PublicCatalog::class);
        self::assertSame('Concurso Exemplo 2027', $public->findBySlug('concurso-exemplo-2027')['snapshot']['contest']['title']);
        self::assertSame('Concurso Exemplo 2027', $this->db->scalar('SELECT title FROM contest_search WHERE contest_id = ?', [$id]));

        $this->publication()->submit($id, $this->version($id), $editor);
        self::assertSame(2, $this->publication()->publish($id, $this->version($id), $editor, 'Título corrigido conforme retificação', true, true));
        $page = $public->findBySlug('concurso-exemplo-2027');
        self::assertSame('Título corrigido', $page['snapshot']['contest']['title']);
        self::assertSame([2, 1], array_column($page['history'], 'revision_no'));
        self::assertSame(1, $page['history'][0]['is_correction']);
        self::assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM contest_revisions WHERE contest_id = ? AND revision_no = 1 AND JSON_UNQUOTE(JSON_EXTRACT(snapshot, \'$.contest.title\')) = ?', [$id, 'Concurso Exemplo 2027']), 'Revisão 1 continua intacta');
    }

    public function testStaleVersionIsRejectedForContestAndChildren(): void
    {
        $editor = $this->staff();
        $id = $this->contest($editor->id);
        $v = $this->version($id);
        $data = ['title' => 'Primeira aba'];
        $this->contests()->update($id, $v, null, $data, $editor->id);

        try {
            $this->contests()->update($id, $v, null, ['title' => 'Segunda aba'], $editor->id);
            self::fail('Esperado conflito de versão');
        } catch (StaleVersion $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame('Primeira aba', $this->db->scalar('SELECT title FROM contests WHERE id = ?', [$id]));

        $this->expectException(StaleVersion::class);
        $this->contests()->saveEvent($id, $v, null, ['type' => 'exam', 'label' => null, 'date_value' => null, 'date_precision' => 'unknown', 'time_local' => null, 'note' => null], $editor->id);
    }

    public function testChildFromAnotherContestCannotBeChangedOrDeleted(): void
    {
        $editor = $this->staff();
        $a = $this->contest($editor->id, [], 'concurso-a');
        $b = $this->contest($editor->id, [], 'concurso-b');
        $this->offer($b, $editor->id, ['title' => 'Cargo de B']);
        $offerB = (int) $this->db->scalar('SELECT id FROM contest_offers WHERE contest_id = ?', [$b]);

        try {
            $this->contests()->saveOffer($a, $this->version($a), $offerB, ['title' => 'Invadido'], $editor->id);
            self::fail('Esperada recusa');
        } catch (WorkflowViolation) {
        }
        self::assertFalse($this->contests()->deleteOffer($a, $this->version($a), $offerB, $editor->id));
        self::assertSame('Cargo de B', $this->db->scalar('SELECT title FROM contest_offers WHERE id = ?', [$offerB]));
    }

    public function testPublishRequiresReviewSourceVerificationAndNote(): void
    {
        $editor = $this->staff();
        $id = $this->contest($editor->id, ['source_url' => null]);
        $this->offer($id, $editor->id);

        $this->assertViolation(fn () => $this->publication()->publish($id, $this->version($id), $editor, 'Nota válida', false, true), 'Envie o concurso para revisão');
        $this->assertViolation(fn () => $this->publication()->submit($id, $this->version($id), $editor), 'documento oficial');

        $this->db->run('UPDATE contests SET source_url = ? WHERE id = ?', ['https://example.com/edital.pdf', $id]);
        $this->publication()->submit($id, $this->version($id), $editor);
        $this->assertViolation(fn () => $this->publication()->publish($id, $this->version($id), $editor, 'Nota válida', false, false), 'conferiu');
        $this->assertViolation(fn () => $this->publication()->publish($id, $this->version($id), $editor, ' ok ', false, true), 'histórico público');
        self::assertNull($this->db->scalar('SELECT published_revision_id FROM contests WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM contest_search'));
    }

    public function testEditalPublishedNeedsOfferAndCoherentRegistrationDates(): void
    {
        $editor = $this->staff();
        $id = $this->contest($editor->id);
        $this->assertViolation(fn () => $this->publication()->submit($id, $this->version($id), $editor), 'ao menos um cargo');

        $this->offer($id, $editor->id);
        $this->event($id, $editor->id, 'registration_start', '2027-03-10');
        $this->event($id, $editor->id, 'registration_end', '2027-03-01');
        $this->assertViolation(fn () => $this->publication()->submit($id, $this->version($id), $editor), 'antes do início');
    }

    public function testFourEyesRequiresAnotherPublisherWhenEnabled(): void
    {
        putenv('CATALOG_FOUR_EYES=true');
        try {
            $this->app = (require dirname(__DIR__, 3) . '/bootstrap/app.php')(dirname(__DIR__, 3));
            $this->clock = $this->app->container->get(\Concursei\Core\Clock::class);
            $this->clock->setNow(new DateTimeImmutable('2027-02-15 12:00:00'));
            $editor = $this->staff();
            $admin = $this->staff('admin@example.com', 'admin');
            $id = $this->contest($editor->id);
            $this->offer($id, $editor->id);
            $this->publication()->submit($id, $this->version($id), $editor);

            $this->assertViolation(fn () => $this->publication()->publish($id, $this->version($id), $editor, 'Conferido com o edital', false, true), 'duas pessoas');
            self::assertSame(1, $this->publication()->publish($id, $this->version($id), $admin, 'Conferido com o edital', false, true));
        } finally {
            putenv('CATALOG_FOUR_EYES');
        }
    }

    public function testArchiveHidesFromPublicAndUnarchiveRestoresLastRevision(): void
    {
        $editor = $this->staff();
        $id = $this->publishedContest($editor);
        $public = $this->app->container->get(PublicCatalog::class);

        $this->publication()->archive($id, $editor);
        self::assertNull($public->findBySlug('concurso-exemplo-2027'));
        $this->assertViolation(fn () => $this->publication()->archive($id, $editor), 'publicados e ativos');

        $this->publication()->unarchive($id, $editor);
        self::assertNotNull($public->findBySlug('concurso-exemplo-2027'));
    }

    public function testOnlyNeverPublishedContestsCanBeDeleted(): void
    {
        $editor = $this->staff();
        $draft = $this->contest($editor->id, [], 'rascunho');
        $this->offer($draft, $editor->id);
        $this->publication()->delete($draft, $this->version($draft), $editor);
        self::assertNull($this->db->scalar('SELECT id FROM contests WHERE id = ?', [$draft]));
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM contest_offers WHERE contest_id = ?', [$draft]));

        $published = $this->publishedContest($editor);
        $this->assertViolation(fn () => $this->publication()->delete($published, $this->version($published), $editor), 'Arquivar');
    }

    public function testDailyRefreshMovesPhaseWithTheCalendar(): void
    {
        $editor = $this->staff();
        $id = $this->publishedContest($editor);
        $index = $this->app->container->get(SearchIndex::class);
        self::assertSame(0, $index->refreshAll());

        // 01/03/2027 02:00 UTC ainda é 28/02 em Brasília: nada muda.
        $this->clock->setNow(new DateTimeImmutable('2027-03-01 02:00:00'));
        self::assertSame(0, $index->refreshAll());
        self::assertSame('edital_published', $this->db->scalar('SELECT phase FROM contest_search WHERE contest_id = ?', [$id]));

        $this->clock->setNow(new DateTimeImmutable('2027-03-01 12:00:00'));
        self::assertSame(1, $index->refreshAll());
        $row = $this->db->one('SELECT phase, next_event_type, next_event_on, refreshed_on FROM contest_search WHERE contest_id = ?', [$id]);
        self::assertSame(['registration_open', 'registration_start', '2027-03-01', '2027-03-01'], array_values($row));

        $this->clock->setNow(new DateTimeImmutable('2027-04-01 12:00:00'));
        $index->refreshAll();
        $row = $this->db->one('SELECT phase, next_event_type, next_event_on FROM contest_search WHERE contest_id = ?', [$id]);
        self::assertSame(['registration_closed', 'exam', '2027-05-10'], array_values($row));
    }

    private function assertViolation(callable $fn, string $fragment): void
    {
        try {
            $fn();
            self::fail('Esperada WorkflowViolation contendo: ' . $fragment);
        } catch (WorkflowViolation $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }
}
