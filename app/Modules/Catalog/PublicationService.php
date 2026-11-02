<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Shared\Audit\AuditLog;
use Concursei\Shared\Auth\AuthUser;

/**
 * Fluxo editorial: rascunho → em revisão → publicado. Publicar grava uma
 * revisão imutável (snapshot JSON exibido ao público e no histórico) e
 * reconstrói o modelo de leitura. A conferência com a fonte oficial é
 * declarada por uma pessoa da equipe — nunca inferida de confiança de IA.
 */
final class PublicationService
{
    public const SNAPSHOT_SCHEMA = 1;

    public function __construct(
        private Database $db,
        private ContestRepository $contests,
        private ReferenceRepository $refs,
        private SearchIndex $index,
        private AuditLog $audit,
        private Clock $clock,
        private Config $config,
    ) {
    }

    /** @throws StaleVersion|WorkflowViolation */
    public function submit(int $id, int $version, AuthUser $user): void
    {
        $this->db->transaction(function () use ($id, $version, $user): void {
            $c = $this->lock($id);
            if ($c['workflow_status'] !== 'draft' || $c['archived_at'] !== null) {
                throw new WorkflowViolation('Somente rascunhos ativos podem ser enviados para revisão.');
            }
            $this->assertPublishable($c);
            $this->contests->touch($id, $version, $user->id, 'in_review');
            $this->db->run('UPDATE contests SET submitted_by = ? WHERE id = ?', [$user->id, $id]);
        });
        $this->audit->record($user->id, 'contest.submitted', 'contest', $id);
    }

    /** @throws StaleVersion|WorkflowViolation */
    public function returnToDraft(int $id, int $version, AuthUser $user): void
    {
        $this->db->transaction(function () use ($id, $version, $user): void {
            $c = $this->lock($id);
            if ($c['workflow_status'] !== 'in_review') {
                throw new WorkflowViolation('O concurso não está em revisão.');
            }
            $this->contests->touch($id, $version, $user->id, 'draft');
        });
        $this->audit->record($user->id, 'contest.returned', 'contest', $id);
    }

    /**
     * @return int número da revisão publicada
     * @throws StaleVersion|WorkflowViolation
     */
    public function publish(int $id, int $version, AuthUser $user, string $changeNote, bool $isCorrection, bool $verified): int
    {
        if (!$verified) {
            throw new WorkflowViolation('Confirme que você conferiu as informações com o documento oficial antes de publicar.');
        }
        $changeNote = trim((string) preg_replace('/\s+/u', ' ', $changeNote));
        if (mb_strlen($changeNote) < 5 || mb_strlen($changeNote) > 500) {
            throw new WorkflowViolation('Descreva o que foi publicado ou alterado (5 a 500 caracteres) — o texto aparece no histórico público.');
        }
        [$revisionNo, $correction] = $this->db->transaction(function () use ($id, $version, $user, $changeNote, $isCorrection): array {
            $c = $this->lock($id);
            if ($c['workflow_status'] !== 'in_review') {
                throw new WorkflowViolation('Envie o concurso para revisão antes de publicar.');
            }
            if ($this->config->bool('catalog.four_eyes') && (int) $c['submitted_by'] === $user->id) {
                throw new WorkflowViolation('A revisão em duas pessoas está ativa: outra pessoa da equipe precisa publicar.');
            }
            $this->assertPublishable($c);
            $this->contests->touch($id, $version, $user->id, 'published');
            $now = $this->clock->nowDb();
            $no = (int) $this->db->scalar('SELECT COALESCE(MAX(revision_no), 0) + 1 FROM contest_revisions WHERE contest_id = ?', [$id]);
            // "Correção" só faz sentido quando já havia informação publicada.
            $correction = $isCorrection && $no > 1;
            $revisionId = $this->db->insert(
                'INSERT INTO contest_revisions (contest_id, revision_no, snapshot, change_note, is_correction, published_by, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $no, json_encode($this->snapshot($c), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $changeNote, $correction ? 1 : 0, $user->id, $now]
            );
            $this->db->run(
                'UPDATE contests SET published_revision_id = ?, archived_at = NULL, last_verified_at = ? WHERE id = ?',
                [$revisionId, $now, $id]
            );
            $this->index->rebuild($id);
            return [$no, $correction];
        });
        $this->audit->record($user->id, 'contest.published', 'contest', $id, ['revision' => $revisionNo, 'correction' => $correction]);
        return $revisionNo;
    }

    /** Retira do catálogo público (a revisão e os favoritos são mantidos). */
    public function archive(int $id, AuthUser $user): void
    {
        $this->db->transaction(function () use ($id): void {
            $c = $this->lock($id);
            if ($c['published_revision_id'] === null || $c['archived_at'] !== null) {
                throw new WorkflowViolation('Somente concursos publicados e ativos podem ser arquivados.');
            }
            $this->db->run('UPDATE contests SET archived_at = ? WHERE id = ?', [$this->clock->nowDb(), $id]);
            $this->index->rebuild($id);
        });
        $this->audit->record($user->id, 'contest.archived', 'contest', $id);
    }

    /** Volta a exibir a última revisão publicada. */
    public function unarchive(int $id, AuthUser $user): void
    {
        $this->db->transaction(function () use ($id): void {
            $c = $this->lock($id);
            if ($c['archived_at'] === null) {
                throw new WorkflowViolation('O concurso não está arquivado.');
            }
            $this->db->run('UPDATE contests SET archived_at = NULL WHERE id = ?', [$id]);
            $this->index->rebuild($id);
        });
        $this->audit->record($user->id, 'contest.unarchived', 'contest', $id);
    }

    /** Exclusão definitiva só para quem nunca foi publicado (o histórico público é preservado). */
    public function delete(int $id, int $version, AuthUser $user): void
    {
        $this->db->transaction(function () use ($id, $version): void {
            $c = $this->lock($id);
            if ($c['published_revision_id'] !== null || $this->db->scalar('SELECT 1 FROM contest_revisions WHERE contest_id = ? LIMIT 1', [$id]) !== null) {
                throw new WorkflowViolation('Concursos já publicados não podem ser excluídos; use "Arquivar".');
            }
            if ((int) $c['version'] !== $version) {
                throw new StaleVersion();
            }
            $this->db->run('DELETE FROM contests WHERE id = ?', [$id]);
        });
        $this->audit->record($user->id, 'contest.deleted', 'contest', $id);
    }

    /**
     * Pendências que impedem enviar para revisão/publicar.
     * @param array<string,mixed> $c linha de contests
     * @return list<string>
     */
    public function blockers(array $c): array
    {
        $out = [];
        if ($c['source_url'] === null || $c['source_url'] === '') {
            $out[] = 'Informe o endereço do documento oficial usado na conferência (fonte).';
        }
        $offers = (int) $this->db->scalar('SELECT COUNT(*) FROM contest_offers WHERE contest_id = ?', [$c['id']]);
        if ($c['status'] === 'edital_published' && $offers === 0) {
            $out[] = 'Com edital publicado, cadastre ao menos um cargo.';
        }
        $reg = $this->db->one(
            "SELECT MIN(CASE WHEN type = 'registration_start' THEN date_value END) AS s, MAX(CASE WHEN type = 'registration_end' THEN date_value END) AS e
               FROM contest_events WHERE contest_id = ? AND date_precision = 'day'",
            [$c['id']]
        );
        if ($reg !== null && $reg['s'] !== null && $reg['e'] !== null && $reg['e'] < $reg['s']) {
            $out[] = 'O fim das inscrições está antes do início.';
        }
        return $out;
    }

    /** @param array<string,mixed> $c */
    private function assertPublishable(array $c): void
    {
        $blockers = $this->blockers($c);
        if ($blockers !== []) {
            throw new WorkflowViolation(implode(' ', $blockers));
        }
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        $c = $this->db->one('SELECT * FROM contests WHERE id = ? FOR UPDATE', [$id]);
        if ($c === null) {
            throw new WorkflowViolation('Concurso não encontrado.');
        }
        return $c;
    }

    /**
     * Snapshot autocontido: nomes de órgão/banca/cargo são copiados para que
     * edições futuras nos cadastros de apoio não alterem o que foi publicado.
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function snapshot(array $c): array
    {
        $org = $this->refs->find('organizations', (int) $c['organization_id']) ?? throw new WorkflowViolation('Órgão não encontrado.');
        $board = $c['exam_board_id'] !== null ? $this->refs->find('exam_boards', (int) $c['exam_board_id']) : null;
        $pick = static fn (array $row, array $keys): array => array_intersect_key($row, array_flip($keys));
        return [
            'schema' => self::SNAPSHOT_SCHEMA,
            'contest' => $pick($c, ['slug', 'title', 'edition_year', 'uf', 'status', 'summary', 'fee_cents', 'fee_note', 'official_url', 'registration_url', 'source_url', 'source_published_on']),
            'organization' => $pick($org, ['slug', 'name', 'acronym', 'sphere', 'uf', 'website_url']),
            'exam_board' => $board !== null ? $pick($board, ['slug', 'name', 'acronym', 'website_url']) : null,
            'offers' => array_map(static fn (array $o): array => [
                'title' => $o['title'],
                'education_level' => $o['education_level'],
                'vacancies' => $o['vacancies'] !== null ? (int) $o['vacancies'] : null,
                'reserve' => (bool) $o['reserve'],
                'salary_cents' => $o['salary_cents'] !== null ? (int) $o['salary_cents'] : null,
                'salary_note' => $o['salary_note'],
                'workload_hours' => $o['workload_hours'] !== null ? (int) $o['workload_hours'] : null,
                'requirements' => $o['requirements'],
                'location' => $o['location'],
                'career' => $o['career_slug'] !== null ? ['slug' => $o['career_slug'], 'name' => $o['career_name'], 'area' => $o['career_area']] : null,
            ], $this->contests->offers((int) $c['id'])),
            'events' => array_map(static fn (array $e): array => [
                'type' => $e['type'],
                'label' => $e['label'],
                'date' => $e['date_value'],
                'precision' => $e['date_precision'],
                'time' => $e['time_local'],
                'note' => $e['note'],
            ], $this->contests->events((int) $c['id'])),
        ];
    }
}
