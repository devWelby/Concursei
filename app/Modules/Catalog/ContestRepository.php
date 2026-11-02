<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;

/**
 * Cópia de trabalho dos concursos (área administrativa). Toda alteração —
 * inclusive em cargos e datas — incrementa contests.version com verificação
 * otimista e devolve o concurso a "rascunho": o público só vê revisões publicadas.
 */
final class ContestRepository
{
    private const FIELDS = [
        'organization_id', 'exam_board_id', 'title', 'edition_year', 'uf', 'status', 'summary',
        'fee_cents', 'fee_note', 'official_url', 'registration_url', 'source_url', 'source_published_on',
    ];
    private const OFFER_FIELDS = [
        'career_id', 'title', 'education_level', 'vacancies', 'reserve', 'salary_cents', 'salary_note',
        'workload_hours', 'requirements', 'location', 'sort_order',
    ];
    private const EVENT_FIELDS = ['type', 'label', 'date_value', 'date_precision', 'time_local', 'note'];
    private const MAX_OFFERS = 300;
    private const MAX_EVENTS = 60;

    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT c.*, o.name AS organization_name, o.acronym AS organization_acronym, b.name AS exam_board_name,
                    r.revision_no AS published_revision_no, r.published_at AS published_at
               FROM contests c
               JOIN organizations o ON o.id = c.organization_id
               LEFT JOIN exam_boards b ON b.id = c.exam_board_id
               LEFT JOIN contest_revisions r ON r.id = c.published_revision_id
              WHERE c.id = ?',
            [$id]
        );
    }

    /**
     * @param array{workflow?:?string,q?:?string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        $workflow = $filters['workflow'] ?? null;
        if ($workflow === 'archived') {
            $where[] = 'c.archived_at IS NOT NULL';
        } elseif (in_array($workflow, ['draft', 'in_review', 'published'], true)) {
            $where[] = 'c.workflow_status = ? AND c.archived_at IS NULL';
            $params[] = $workflow;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . Database::likeEscape($q) . '%';
            $where[] = '(c.title LIKE ? OR o.name LIKE ? OR o.acronym LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM contests c JOIN organizations o ON o.id = c.organization_id {$sqlWhere}", $params);
        $rows = $this->db->all(
            "SELECT c.id, c.slug, c.title, c.status, c.workflow_status, c.archived_at, c.updated_at, c.last_verified_at,
                    c.published_revision_id, o.name AS organization_name, o.acronym AS organization_acronym
               FROM contests c JOIN organizations o ON o.id = c.organization_id
               {$sqlWhere}
              ORDER BY c.updated_at DESC, c.id DESC
              LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{draft:int,in_review:int,published:int,archived:int} */
    public function counts(): array
    {
        $out = ['draft' => 0, 'in_review' => 0, 'published' => 0, 'archived' => 0];
        foreach ($this->db->all('SELECT workflow_status AS w, archived_at IS NOT NULL AS a, COUNT(*) AS n FROM contests GROUP BY w, a') as $r) {
            $key = (int) $r['a'] === 1 ? 'archived' : (string) $r['w'];
            $out[$key] += (int) $r['n'];
        }
        return $out;
    }

    /**
     * Publicados e ativos cuja última conferência é anterior a $before.
     * @return list<array<string,mixed>>
     */
    public function staleVerification(string $before, int $limit = 20): array
    {
        return $this->db->all(
            "SELECT id, title, last_verified_at FROM contests
              WHERE published_revision_id IS NOT NULL AND archived_at IS NULL AND status NOT IN ('concluded', 'cancelled')
                AND (last_verified_at IS NULL OR last_verified_at < ?)
              ORDER BY last_verified_at LIMIT {$limit}",
            [$before]
        );
    }

    /**
     * @param array<string,mixed> $data
     * @throws SlugTaken
     */
    public function create(string $slug, array $data, int $userId): int
    {
        $cols = array_values(array_intersect(self::FIELDS, array_keys($data)));
        $now = $this->clock->nowDb();
        try {
            return $this->db->insert(
                sprintf(
                    'INSERT INTO contests (slug, %s, workflow_status, version, created_by, updated_by, created_at, updated_at) VALUES (?, %s, \'draft\', 1, ?, ?, ?, ?)',
                    implode(', ', $cols),
                    implode(', ', array_fill(0, count($cols), '?'))
                ),
                [$slug, ...array_map(static fn (string $c): mixed => $data[$c], $cols), $userId, $userId, $now, $now]
            );
        } catch (\PDOException $e) {
            throw Database::isDuplicateKey($e) ? new SlugTaken('Endereço já usado.', 0, $e) : $e;
        }
    }

    /**
     * Atualiza os dados principais. O endereço (slug) só muda enquanto o
     * concurso nunca foi publicado (links públicos não quebram).
     * @param array<string,mixed> $data
     * @throws StaleVersion|SlugTaken
     */
    public function update(int $id, int $expectedVersion, ?string $slug, array $data, int $userId): void
    {
        $cols = array_values(array_intersect(self::FIELDS, array_keys($data)));
        $set = array_map(static fn (string $c): string => $c . ' = ?', $cols);
        $params = array_map(static fn (string $c): mixed => $data[$c], $cols);
        if ($slug !== null) {
            $set[] = 'slug = IF(published_revision_id IS NULL, ?, slug)';
            $params[] = $slug;
        }
        try {
            $this->db->transaction(function () use ($id, $expectedVersion, $userId, $set, $params): void {
                $this->touch($id, $expectedVersion, $userId);
                $this->db->run('UPDATE contests SET ' . implode(', ', $set) . ' WHERE id = ?', [...$params, $id]);
            });
        } catch (\PDOException $e) {
            throw Database::isDuplicateKey($e) ? new SlugTaken('Endereço já usado.', 0, $e) : $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function offers(int $contestId): array
    {
        return $this->db->all(
            'SELECT f.*, k.slug AS career_slug, k.name AS career_name, k.area AS career_area
               FROM contest_offers f LEFT JOIN careers k ON k.id = f.career_id
              WHERE f.contest_id = ? ORDER BY f.sort_order, f.id',
            [$contestId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findOffer(int $contestId, int $offerId): ?array
    {
        return $this->db->one('SELECT * FROM contest_offers WHERE id = ? AND contest_id = ?', [$offerId, $contestId]);
    }

    /**
     * @param array<string,mixed> $data
     * @throws StaleVersion|WorkflowViolation
     */
    public function saveOffer(int $contestId, int $expectedVersion, ?int $offerId, array $data, int $userId): void
    {
        $this->saveChild('contest_offers', self::OFFER_FIELDS, self::MAX_OFFERS, 'cargos', $contestId, $expectedVersion, $offerId, $data, $userId);
    }

    public function deleteOffer(int $contestId, int $expectedVersion, int $offerId, int $userId): bool
    {
        return $this->deleteChild('contest_offers', $contestId, $expectedVersion, $offerId, $userId);
    }

    /** @return list<array<string,mixed>> */
    public function events(int $contestId): array
    {
        return $this->db->all(
            'SELECT * FROM contest_events WHERE contest_id = ? ORDER BY date_value IS NULL, date_value, id',
            [$contestId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findEvent(int $contestId, int $eventId): ?array
    {
        return $this->db->one('SELECT * FROM contest_events WHERE id = ? AND contest_id = ?', [$eventId, $contestId]);
    }

    /**
     * @param array<string,mixed> $data
     * @throws StaleVersion|WorkflowViolation
     */
    public function saveEvent(int $contestId, int $expectedVersion, ?int $eventId, array $data, int $userId): void
    {
        $this->saveChild('contest_events', self::EVENT_FIELDS, self::MAX_EVENTS, 'datas', $contestId, $expectedVersion, $eventId, $data, $userId);
    }

    public function deleteEvent(int $contestId, int $expectedVersion, int $eventId, int $userId): bool
    {
        return $this->deleteChild('contest_events', $contestId, $expectedVersion, $eventId, $userId);
    }

    /** @return list<array<string,mixed>> */
    public function revisions(int $contestId): array
    {
        return $this->db->all(
            'SELECT r.id, r.revision_no, r.change_note, r.is_correction, r.published_at, u.display_name AS published_by_name
               FROM contest_revisions r LEFT JOIN users u ON u.id = r.published_by
              WHERE r.contest_id = ? ORDER BY r.revision_no DESC',
            [$contestId]
        );
    }

    /**
     * Incrementa a versão se ainda for a esperada e volta o fluxo para rascunho.
     * Deve ser chamado dentro de transação, antes da alteração.
     * @throws StaleVersion
     */
    public function touch(int $id, int $expectedVersion, int $userId, string $workflow = 'draft'): void
    {
        $n = $this->db->affected(
            'UPDATE contests SET version = version + 1, workflow_status = ?, updated_by = ?, updated_at = ? WHERE id = ? AND version = ?',
            [$workflow, $userId, $this->clock->nowDb(), $id, $expectedVersion]
        );
        if ($n !== 1) {
            throw new StaleVersion();
        }
    }

    /**
     * @param list<string> $fields
     * @param array<string,mixed> $data
     */
    private function saveChild(string $table, array $fields, int $max, string $what, int $contestId, int $expectedVersion, ?int $childId, array $data, int $userId): void
    {
        $cols = array_values(array_intersect($fields, array_keys($data)));
        $values = array_map(static fn (string $c): mixed => $data[$c], $cols);
        $now = $this->clock->nowDb();
        $this->db->transaction(function () use ($table, $max, $what, $contestId, $expectedVersion, $childId, $cols, $values, $now, $userId): void {
            $this->touch($contestId, $expectedVersion, $userId);
            if ($childId === null) {
                if ((int) $this->db->scalar("SELECT COUNT(*) FROM {$table} WHERE contest_id = ?", [$contestId]) >= $max) {
                    throw new WorkflowViolation("Limite de {$max} {$what} por concurso atingido.");
                }
                $this->db->run(
                    sprintf('INSERT INTO %s (contest_id, %s, created_at, updated_at) VALUES (?, %s, ?, ?)', $table, implode(', ', $cols), implode(', ', array_fill(0, count($cols), '?'))),
                    [$contestId, ...$values, $now, $now]
                );
                return;
            }
            $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $cols));
            // contest_id no WHERE: um item de outro concurso nunca é alterado por esta rota.
            $n = $this->db->affected("UPDATE {$table} SET {$set}, updated_at = ? WHERE id = ? AND contest_id = ?", [...$values, $now, $childId, $contestId]);
            if ($n === 0 && $this->db->scalar("SELECT 1 FROM {$table} WHERE id = ? AND contest_id = ?", [$childId, $contestId]) === null) {
                throw new WorkflowViolation('Item não encontrado neste concurso.');
            }
        });
    }

    private function deleteChild(string $table, int $contestId, int $expectedVersion, int $childId, int $userId): bool
    {
        return $this->db->transaction(function () use ($table, $contestId, $expectedVersion, $childId, $userId): bool {
            if ($this->db->scalar("SELECT 1 FROM {$table} WHERE id = ? AND contest_id = ?", [$childId, $contestId]) === null) {
                return false;
            }
            $this->touch($contestId, $expectedVersion, $userId);
            $this->db->run("DELETE FROM {$table} WHERE id = ? AND contest_id = ?", [$childId, $contestId]);
            return true;
        });
    }
}
