<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Database\Database;
use DateTimeImmutable;

/**
 * Consultas públicas. Lê SOMENTE o modelo de leitura e as revisões
 * publicadas — nunca a cópia de trabalho editável.
 */
final class PublicCatalog
{
    public function __construct(private Database $db, private LocalDate $date)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(ContestFilters $f, int $perPage): array
    {
        [$where, $params] = $this->where($f);
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM contest_search cs {$sqlWhere}", $params);
        $orderParams = [];
        $order = match ($f->sort) {
            // Inscrições ainda abertas primeiro, pelo prazo mais próximo.
            'inscricoes' => '(cs.registration_ends_on IS NULL OR cs.registration_ends_on < ?), cs.registration_ends_on',
            'proximos' => 'cs.next_event_on IS NULL, cs.next_event_on',
            'vagas' => 'cs.vacancies_total IS NULL, cs.vacancies_total DESC',
            'salario' => 'cs.salary_max_cents IS NULL, cs.salary_max_cents DESC',
            default => 'cs.published_at DESC',
        };
        if ($f->sort === 'inscricoes') {
            $orderParams[] = $this->date->today()->format('Y-m-d');
        }
        $offset = ($f->page - 1) * $perPage;
        $rows = $this->db->all(
            "SELECT cs.* FROM contest_search cs {$sqlWhere} ORDER BY {$order}, cs.contest_id DESC LIMIT {$perPage} OFFSET {$offset}",
            [...$params, ...$orderParams]
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Entrada pública + snapshot da revisão publicada + histórico de revisões.
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->one(
            'SELECT cs.*, r.snapshot, r.revision_no
               FROM contest_search cs
               JOIN contests c ON c.id = cs.contest_id
               JOIN contest_revisions r ON r.id = c.published_revision_id
              WHERE cs.slug = ?',
            [$slug]
        );
        if ($row === null) {
            return null;
        }
        $row['snapshot'] = SearchIndex::decode((string) $row['snapshot']);
        $row['history'] = $this->db->all(
            'SELECT revision_no, change_note, is_correction, published_at FROM contest_revisions WHERE contest_id = ? ORDER BY revision_no DESC',
            [$row['contest_id']]
        );
        return $row;
    }

    /** Endereço público do concurso, se publicado e ativo. */
    public function slugFor(int $contestId): ?string
    {
        $slug = $this->db->scalar('SELECT slug FROM contest_search WHERE contest_id = ?', [$contestId]);
        return $slug === null ? null : (string) $slug;
    }

    /**
     * Eventos do mês: datas exatas por dia + datas conhecidas só pelo mês.
     * @return array{days:array<string,list<array<string,mixed>>>,month:list<array<string,mixed>>}
     */
    public function calendar(DateTimeImmutable $monthStart, ?string $uf, ?string $region, ?string $type): array
    {
        $where = ["e.date_precision IN ('day', 'month')", 'e.date_value BETWEEN ? AND ?'];
        $params = [$monthStart->format('Y-m-01'), $monthStart->format('Y-m-t')];
        if ($uf === 'nacional') {
            $where[] = 'cs.uf IS NULL';
        } elseif ($uf !== null) {
            $where[] = 'cs.uf = ?';
            $params[] = $uf;
        }
        if ($region !== null) {
            $where[] = 'cs.region = ?';
            $params[] = $region;
        }
        if ($type !== null) {
            $where[] = 'e.type = ?';
            $params[] = $type;
        }
        $rows = $this->db->all(
            'SELECT e.type, e.label, e.date_value, e.date_precision, e.time_local, cs.slug, cs.title, cs.uf, cs.phase
               FROM contest_search_events e JOIN contest_search cs ON cs.contest_id = e.contest_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY e.date_value, e.time_local IS NULL, e.time_local, cs.title
              LIMIT 1000',
            $params
        );
        $out = ['days' => [], 'month' => []];
        foreach ($rows as $r) {
            if ($r['date_precision'] === 'day') {
                $out['days'][(string) $r['date_value']][] = $r;
            } else {
                $out['month'][] = $r;
            }
        }
        return $out;
    }

    /** Órgãos com concurso publicado (opções do filtro). @return list<array{slug:string,name:string}> */
    public function organizations(): array
    {
        return $this->db->all(
            'SELECT organization_slug AS slug, MAX(COALESCE(organization_acronym, organization_name)) AS name
               FROM contest_search GROUP BY organization_slug ORDER BY name'
        );
    }

    /** @return list<array{slug:string,name:string}> */
    public function boards(): array
    {
        return $this->db->all(
            'SELECT exam_board_slug AS slug, MAX(exam_board_name) AS name
               FROM contest_search WHERE exam_board_slug IS NOT NULL GROUP BY exam_board_slug ORDER BY name'
        );
    }

    /** @return array{0:list<string>,1:list<mixed>} */
    private function where(ContestFilters $f): array
    {
        $w = [];
        $p = [];
        if ($f->q !== null) {
            $like = '%' . Database::likeEscape($f->q) . '%';
            $w[] = '(cs.title LIKE ? OR cs.organization_name LIKE ? OR cs.organization_acronym LIKE ? OR cs.exam_board_name LIKE ?
                     OR EXISTS (SELECT 1 FROM contest_search_offers so WHERE so.contest_id = cs.contest_id AND so.title LIKE ?))';
            array_push($p, $like, $like, $like, $like, $like);
        }
        if ($f->uf === 'nacional') {
            $w[] = 'cs.uf IS NULL';
        } elseif ($f->uf !== null) {
            $w[] = 'cs.uf = ?';
            $p[] = $f->uf;
        }
        if ($f->region !== null) {
            $w[] = 'cs.region = ?';
            $p[] = $f->region;
        }
        if ($f->area !== null) {
            $w[] = 'EXISTS (SELECT 1 FROM contest_search_offers so WHERE so.contest_id = cs.contest_id AND so.area = ?)';
            $p[] = $f->area;
        }
        if ($f->level !== null) {
            $w[] = 'EXISTS (SELECT 1 FROM contest_search_offers so WHERE so.contest_id = cs.contest_id AND so.education_level = ?)';
            $p[] = $f->level;
        }
        if ($f->organization !== null) {
            $w[] = 'cs.organization_slug = ?';
            $p[] = $f->organization;
        }
        if ($f->board !== null) {
            $w[] = 'cs.exam_board_slug = ?';
            $p[] = $f->board;
        }
        $phases = $f->situation !== null ? Vocabulary::SITUATIONS[$f->situation]['phases'] : null;
        if ($phases !== null) {
            $w[] = 'cs.phase IN (' . implode(', ', array_fill(0, count($phases), '?')) . ')';
            array_push($p, ...$phases);
        } else {
            $w[] = "cs.phase NOT IN ('concluded', 'cancelled')";
        }
        return [$w, $p];
    }
}
