<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;

/**
 * Concursos acompanhados (dado pessoal: apagado com a conta). Toda consulta
 * e remoção filtra por user_id — o id do favorito sozinho não dá acesso.
 */
final class FavoriteRepository
{
    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** Idempotente: acompanhar de novo não duplica. */
    public function add(int $userId, int $contestId): void
    {
        $this->db->run(
            'INSERT INTO favorites (user_id, contest_id, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$userId, $contestId, $this->clock->nowDb()]
        );
    }

    /** @return int|null contest_id removido; null se não existe ou é de outra pessoa */
    public function remove(int $userId, int $favoriteId): ?int
    {
        $contestId = $this->db->scalar('SELECT contest_id FROM favorites WHERE id = ? AND user_id = ?', [$favoriteId, $userId]);
        if ($contestId === null) {
            return null;
        }
        $this->db->run('DELETE FROM favorites WHERE id = ? AND user_id = ?', [$favoriteId, $userId]);
        return (int) $contestId;
    }

    public function idFor(int $userId, int $contestId): ?int
    {
        $id = $this->db->scalar('SELECT id FROM favorites WHERE user_id = ? AND contest_id = ?', [$userId, $contestId]);
        return $id === null ? null : (int) $id;
    }

    public function count(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$userId]);
    }

    /**
     * Painel: concursos acompanhados com fase e próximo evento públicos.
     * Arquivados continuam listados (com o título da última revisão publicada).
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->db->all(
            "SELECT f.id AS favorite_id, f.contest_id, cs.slug, cs.phase, cs.uf, cs.next_event_on, cs.next_event_type,
                    cs.registration_ends_on, cs.organization_name, cs.organization_acronym, cs.vacancies_total, cs.has_reserve,
                    COALESCE(cs.title, JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.contest.title'))) AS title,
                    cs.contest_id IS NULL AS archived
               FROM favorites f
               JOIN contests c ON c.id = f.contest_id
               LEFT JOIN contest_revisions r ON r.id = c.published_revision_id
               LEFT JOIN contest_search cs ON cs.contest_id = f.contest_id
              WHERE f.user_id = ?
              ORDER BY archived, cs.next_event_on IS NULL, cs.next_event_on, title",
            [$userId]
        );
    }

    /**
     * Eventos com data exata dos concursos acompanhados, entre $from e $to (inclusive).
     * @return list<array<string,mixed>>
     */
    public function upcomingEvents(int $userId, string $from, string $to, int $limit = 30): array
    {
        return $this->db->all(
            "SELECT e.type, e.label, e.date_value, e.time_local, cs.slug, cs.title
               FROM favorites f
               JOIN contest_search cs ON cs.contest_id = f.contest_id
               JOIN contest_search_events e ON e.contest_id = cs.contest_id
              WHERE f.user_id = ? AND e.date_precision = 'day' AND e.date_value BETWEEN ? AND ?
              ORDER BY e.date_value, e.time_local IS NULL, e.time_local, cs.title
              LIMIT {$limit}",
            [$userId, $from, $to]
        );
    }
}
