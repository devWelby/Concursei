<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;

/** Fontes cadastradas e documentos coletados. Listagens nunca carregam o texto completo. */
class SourceRepository
{
    public const FIELDS = ['name', 'kind', 'method', 'url', 'extra_hosts', 'organization_id', 'exam_board_id', 'contest_id', 'check_every_hours', 'terms_note', 'enabled'];

    private const DOC_COLUMNS = 'id, source_id, url, sha256, mime_type, bytes, storage_path, title, fetched_at, text_status, text_note, page_count, text_chars';

    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all(
            'SELECT s.*, c.title AS contest_title, (SELECT COUNT(*) FROM source_documents d WHERE d.source_id = s.id) AS documents
             FROM sources s LEFT JOIN contests c ON c.id = s.contest_id ORDER BY s.enabled DESC, s.name'
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT s.*, c.title AS contest_title FROM sources s LEFT JOIN contests c ON c.id = s.contest_id WHERE s.id = ?', [$id]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, int $userId): int
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $now = $this->clock->nowDb();
        $cols = array_keys($data);
        return $this->db->insert(
            'INSERT INTO sources (' . implode(', ', $cols) . ', created_by, created_at, updated_at) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?)',
            [...array_values($data), $userId, $now, $now]
        );
    }

    /** Alterar a URL zera o estado de coleta (o próximo ciclo trata a nova URL como inédita). @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $current = $this->find($id);
        $sets = array_map(static fn (string $c): string => $c . ' = ?', array_keys($data));
        $params = array_values($data);
        if ($current !== null && isset($data['url']) && $data['url'] !== $current['url']) {
            $sets[] = 'etag = NULL, last_modified = NULL, last_hash = NULL, next_check_at = NULL, health = \'unknown\', consecutive_failures = 0, last_error = NULL';
        }
        $sets[] = 'updated_at = ?';
        $params[] = $this->clock->nowDb();
        $params[] = $id;
        $this->db->run('UPDATE sources SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /**
     * Fontes ativas com verificação vencida; já reprograma a próxima para não despachar duas vezes.
     * @return list<int>
     */
    public function claimDue(int $limit): array
    {
        $now = $this->clock->nowDb();
        $ids = array_map('intval', array_column($this->db->all(
            'SELECT id FROM sources WHERE enabled = 1 AND (next_check_at IS NULL OR next_check_at <= ?) ORDER BY next_check_at IS NOT NULL, next_check_at, id LIMIT ' . max(1, min(100, $limit)),
            [$now]
        ), 'id'));
        foreach ($ids as $id) {
            $this->db->run('UPDATE sources SET next_check_at = DATE_ADD(?, INTERVAL check_every_hours HOUR) WHERE id = ?', [$now, $id]);
        }
        return $ids;
    }

    /** @return array<int,string> concursos não arquivados (id => título) */
    public function contestOptions(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, title FROM contests WHERE archived_at IS NULL ORDER BY title LIMIT 1000') as $row) {
            $out[(int) $row['id']] = (string) $row['title'];
        }
        return $out;
    }

    public function markChecked(int $id, ?string $etag, ?string $lastModified, ?string $hash, bool $changed): void
    {
        $now = $this->clock->nowDb();
        $this->db->run(
            'UPDATE sources SET health = \'ok\', consecutive_failures = 0, last_error = NULL, last_checked_at = ?,
                etag = ?, last_modified = ?, last_hash = COALESCE(?, last_hash), last_changed_at = IF(?, ?, last_changed_at),
                next_check_at = DATE_ADD(?, INTERVAL check_every_hours HOUR)
             WHERE id = ?',
            [$now, self::header($etag, 255), self::header($lastModified, 64), $hash, $changed ? 1 : 0, $now, $now, $id]
        );
    }

    public function markFailure(int $id, string $error, bool $blocked): void
    {
        $this->db->run(
            'UPDATE sources SET health = ?, consecutive_failures = consecutive_failures + 1, last_error = ?, last_checked_at = ? WHERE id = ?',
            [$blocked ? 'blocked' : 'failing', mb_substr($error, 0, 255), $this->clock->nowDb(), $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $sourceId, int $limit = 50): array
    {
        return $this->db->all('SELECT ' . self::DOC_COLUMNS . ' FROM source_documents WHERE source_id = ? ORDER BY fetched_at DESC, id DESC LIMIT ' . max(1, min(200, $limit)), [$sourceId]);
    }

    /** @return array<string,mixed>|null inclui o texto extraído */
    public function findDocument(int $id): ?array
    {
        return $this->db->one(
            'SELECT d.*, s.name AS source_name, s.kind AS source_kind, s.contest_id AS source_contest_id FROM source_documents d JOIN sources s ON s.id = d.source_id WHERE d.id = ?',
            [$id]
        );
    }

    public function documentUrlKnown(int $sourceId, string $url): bool
    {
        return $this->db->scalar('SELECT 1 FROM source_documents WHERE source_id = ? AND url = ? LIMIT 1', [$sourceId, $url]) !== null;
    }

    /** @return array{id:int,created:bool} */
    public function addDocument(int $sourceId, string $url, string $sha256, string $mime, int $bytes, string $storagePath): array
    {
        $existing = $this->db->scalar('SELECT id FROM source_documents WHERE source_id = ? AND sha256 = ?', [$sourceId, $sha256]);
        if ($existing !== null) {
            return ['id' => (int) $existing, 'created' => false];
        }
        try {
            $id = $this->db->insert(
                'INSERT INTO source_documents (source_id, url, sha256, mime_type, bytes, storage_path, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$sourceId, $url, $sha256, $mime, $bytes, $storagePath, $this->clock->nowDb()]
            );
            return ['id' => $id, 'created' => true];
        } catch (\PDOException $e) {
            if (!Database::isDuplicateKey($e)) {
                throw $e;
            }
            return ['id' => (int) $this->db->scalar('SELECT id FROM source_documents WHERE source_id = ? AND sha256 = ?', [$sourceId, $sha256]), 'created' => false];
        }
    }

    public function saveText(int $documentId, ExtractedText $t): void
    {
        $this->db->run(
            'UPDATE source_documents SET text_status = ?, text_note = ?, page_count = ?, text_chars = ?, text = ?, title = COALESCE(?, title) WHERE id = ?',
            [$t->status, $t->note, $t->pages, $t->text !== null ? mb_strlen($t->text) : null, $t->text, $t->title !== null ? mb_substr($t->title, 0, 300) : null, $documentId]
        );
    }

    /** Valores de cabeçalho remoto viram dado: tamanho limitado e sem quebras de linha. */
    private static function header(?string $value, int $max): ?string
    {
        if ($value === null || preg_match('/[\r\n\0]/', $value) || strlen($value) > $max) {
            return null;
        }
        return $value;
    }
}
