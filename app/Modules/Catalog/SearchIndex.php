<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Database\Database;

/**
 * Modelo de leitura público (contest_search*). Sempre derivado da revisão
 * publicada — nunca da cópia de trabalho — e recalculado pelo Cron diário,
 * porque a fase muda com a passagem dos dias.
 */
final class SearchIndex
{
    public function __construct(private Database $db, private LocalDate $date)
    {
    }

    /** Reconstrói (ou remove, se arquivado/não publicado) a entrada pública do concurso. */
    public function rebuild(int $contestId): void
    {
        $this->db->transaction(function () use ($contestId): void {
            $this->db->run('DELETE FROM contest_search WHERE contest_id = ?', [$contestId]);
            $row = $this->db->one(
                'SELECT c.archived_at, r.snapshot, r.published_at
                   FROM contests c JOIN contest_revisions r ON r.id = c.published_revision_id
                  WHERE c.id = ?',
                [$contestId]
            );
            if ($row === null || $row['archived_at'] !== null) {
                return;
            }
            $s = self::decode((string) $row['snapshot']);
            $c = $s['contest'];
            $events = self::phaseEvents($s['events']);
            $today = $this->date->today();
            $next = ContestPhase::nextEvent($events, $today);

            $vacancies = null;
            $reserve = false;
            $salaryMax = null;
            foreach ($s['offers'] as $o) {
                if ($o['vacancies'] !== null) {
                    $vacancies = ($vacancies ?? 0) + (int) $o['vacancies'];
                }
                $reserve = $reserve || (bool) $o['reserve'];
                if ($o['salary_cents'] !== null) {
                    $salaryMax = max($salaryMax ?? 0, (int) $o['salary_cents']);
                }
            }

            $this->db->run(
                'INSERT INTO contest_search (contest_id, slug, title, organization_name, organization_slug, organization_acronym,
                    exam_board_name, exam_board_slug, uf, region, status, phase, next_event_on, next_event_type,
                    registration_ends_on, vacancies_total, has_reserve, salary_max_cents, published_at, refreshed_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $contestId, $c['slug'], $c['title'], $s['organization']['name'], $s['organization']['slug'], $s['organization']['acronym'],
                    $s['exam_board']['name'] ?? null, $s['exam_board']['slug'] ?? null, $c['uf'], Vocabulary::regionOf($c['uf']),
                    $c['status'], ContestPhase::derive($c['status'], $events, $today), $next['date'] ?? null, $next['type'] ?? null,
                    ContestPhase::registrationEnd($events), $vacancies, $reserve ? 1 : 0, $salaryMax, $row['published_at'], $today->format('Y-m-d'),
                ]
            );
            foreach ($s['offers'] as $o) {
                $this->db->run(
                    'INSERT INTO contest_search_offers (contest_id, career_slug, area, education_level, title) VALUES (?, ?, ?, ?, ?)',
                    [$contestId, $o['career']['slug'] ?? null, $o['career']['area'] ?? null, $o['education_level'], $o['title']]
                );
            }
            foreach ($s['events'] as $e) {
                $this->db->run(
                    'INSERT INTO contest_search_events (contest_id, type, label, date_value, date_precision, time_local) VALUES (?, ?, ?, ?, ?, ?)',
                    [$contestId, $e['type'], $e['label'], $e['date'], $e['precision'], $e['time']]
                );
            }
        });
    }

    /**
     * Cron diário: recalcula fase, próximo evento e fim das inscrições.
     * @return int quantidade de concursos cuja fase/datas mudaram
     */
    public function refreshAll(): int
    {
        $today = $this->date->today();
        $events = [];
        foreach ($this->db->all("SELECT contest_id, type, date_value FROM contest_search_events WHERE date_precision = 'day' AND date_value IS NOT NULL") as $e) {
            $events[(int) $e['contest_id']][] = ['type' => (string) $e['type'], 'date' => (string) $e['date_value'], 'precision' => 'day'];
        }
        $changed = 0;
        foreach ($this->db->all('SELECT contest_id, status, phase, next_event_on, next_event_type, registration_ends_on FROM contest_search') as $r) {
            $ev = $events[(int) $r['contest_id']] ?? [];
            $phase = ContestPhase::derive((string) $r['status'], $ev, $today);
            $next = ContestPhase::nextEvent($ev, $today);
            $regEnd = ContestPhase::registrationEnd($ev);
            if ($phase !== $r['phase'] || ($next['date'] ?? null) !== $r['next_event_on'] || ($next['type'] ?? null) !== $r['next_event_type'] || $regEnd !== $r['registration_ends_on']) {
                $this->db->run(
                    'UPDATE contest_search SET phase = ?, next_event_on = ?, next_event_type = ?, registration_ends_on = ? WHERE contest_id = ?',
                    [$phase, $next['date'] ?? null, $next['type'] ?? null, $regEnd, (int) $r['contest_id']]
                );
                $changed++;
            }
        }
        $this->db->run('UPDATE contest_search SET refreshed_on = ?', [$today->format('Y-m-d')]);
        return $changed;
    }

    /** @return array<string,mixed> */
    public static function decode(string $json): array
    {
        $s = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($s) || ($s['schema'] ?? null) !== PublicationService::SNAPSHOT_SCHEMA) {
            throw new \UnexpectedValueException('Snapshot de revisão em formato desconhecido.');
        }
        return $s;
    }

    /**
     * @param list<array<string,mixed>> $events eventos do snapshot
     * @return list<array{type:string,date:?string,precision:string}>
     */
    public static function phaseEvents(array $events): array
    {
        return array_map(static fn (array $e): array => ['type' => (string) $e['type'], 'date' => $e['date'], 'precision' => (string) $e['precision']], $events);
    }
}
