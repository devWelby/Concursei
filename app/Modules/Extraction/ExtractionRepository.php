<?php

declare(strict_types=1);

namespace Concursei\Modules\Extraction;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;

/** Pedidos de extração e propostas validadas (edital_extractions). Transições de estado são condicionais. */
final class ExtractionRepository
{
    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** @param array{document_id:int,contest_id:int,input_sha256:string,requested_by:int,status?:string,result?:?array<string,mixed>,model?:?string,reused_from?:?int} $row */
    public function create(array $row): int
    {
        $now = $this->clock->nowDb();
        return $this->db->insert(
            'INSERT INTO edital_extractions (document_id, contest_id, status, prompt_version, input_sha256, model, reused_from, result, requested_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row['document_id'], $row['contest_id'], $row['status'] ?? 'queued', EditalPrompt::VERSION, $row['input_sha256'],
                $row['model'] ?? null, $row['reused_from'] ?? null, isset($row['result']) ? self::encode($row['result']) : null,
                $row['requested_by'], $now, $now,
            ]
        );
    }

    /** @return array<string,mixed>|null resultado já decodificado em 'proposal' */
    public function find(int $id): ?array
    {
        $row = $this->db->one(
            'SELECT x.*, d.url AS document_url, d.title AS document_title, d.text_status, s.id AS source_id, s.name AS source_name, s.kind AS source_kind,
                    c.title AS contest_title, u.display_name AS requested_by_name, r.display_name AS reviewed_by_name
               FROM edital_extractions x
               JOIN source_documents d ON d.id = x.document_id
               JOIN sources s ON s.id = d.source_id
               LEFT JOIN contests c ON c.id = x.contest_id
               LEFT JOIN users u ON u.id = x.requested_by
               LEFT JOIN users r ON r.id = x.reviewed_by
              WHERE x.id = ?',
            [$id]
        );
        if ($row === null) {
            return null;
        }
        $row['proposal'] = is_string($row['result']) ? json_decode($row['result'], true) : null;
        unset($row['result']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function forDocument(int $documentId): array
    {
        return $this->db->all(
            'SELECT x.id, x.status, x.contest_id, x.created_at, x.error, x.reused_from, c.title AS contest_title
               FROM edital_extractions x LEFT JOIN contests c ON c.id = x.contest_id
              WHERE x.document_id = ? ORDER BY x.id DESC LIMIT 20',
            [$documentId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recent(?string $status, int $limit = 50): array
    {
        $where = $status !== null ? 'WHERE x.status = ?' : '';
        return $this->db->all(
            "SELECT x.id, x.status, x.document_id, x.contest_id, x.created_at, x.reviewed_at, d.title AS document_title, d.url AS document_url,
                    s.kind AS source_kind, c.title AS contest_title
               FROM edital_extractions x
               JOIN source_documents d ON d.id = x.document_id
               JOIN sources s ON s.id = d.source_id
               LEFT JOIN contests c ON c.id = x.contest_id
               {$where}
              ORDER BY x.id DESC LIMIT " . max(1, min(200, $limit)),
            $status !== null ? [$status] : []
        );
    }

    public function active(int $documentId, int $contestId): ?int
    {
        $id = $this->db->scalar(
            "SELECT id FROM edital_extractions WHERE document_id = ? AND contest_id = ? AND status IN ('queued', 'running', 'ready') ORDER BY id DESC LIMIT 1",
            [$documentId, $contestId]
        );
        return $id !== null ? (int) $id : null;
    }

    /**
     * Proposta já validada para exatamente a mesma entrada (versão do prompt + texto): evita pagar de novo.
     * @return array{id:int,model:?string,proposal:array<string,mixed>}|null
     */
    public function cached(string $inputSha256, ?int $exceptId = null): ?array
    {
        $row = $this->db->one(
            "SELECT id, model, result FROM edital_extractions
              WHERE input_sha256 = ? AND prompt_version = ? AND status IN ('ready', 'applied', 'rejected') AND result IS NOT NULL AND id <> ?
              ORDER BY id DESC LIMIT 1",
            [$inputSha256, EditalPrompt::VERSION, $exceptId ?? 0]
        );
        $proposal = $row !== null ? json_decode((string) $row['result'], true) : null;
        return is_array($proposal) ? ['id' => (int) $row['id'], 'model' => $row['model'], 'proposal' => $proposal] : null;
    }

    public function markRunning(int $id): bool
    {
        return $this->db->affected(
            "UPDATE edital_extractions SET status = 'running', updated_at = ? WHERE id = ? AND status IN ('queued', 'running')",
            [$this->clock->nowDb(), $id]
        ) === 1;
    }

    /** Falha temporária: volta para a fila com a mensagem visível na revisão. */
    public function waitRetry(int $id, string $message): void
    {
        $this->db->run(
            "UPDATE edital_extractions SET status = 'queued', error = ?, updated_at = ? WHERE id = ? AND status IN ('queued', 'running')",
            [mb_substr($message, 0, 255), $this->clock->nowDb(), $id]
        );
    }

    /** @param array<string,mixed> $proposal */
    public function ready(int $id, array $proposal, ?string $model, string $inputSha256, ?int $reusedFrom = null): bool
    {
        return $this->db->affected(
            "UPDATE edital_extractions SET status = 'ready', result = ?, model = ?, input_sha256 = ?, reused_from = ?, error = NULL, updated_at = ?
              WHERE id = ? AND status IN ('queued', 'running')",
            [self::encode($proposal), $model !== null ? mb_substr($model, 0, 100) : null, $inputSha256, $reusedFrom, $this->clock->nowDb(), $id]
        ) === 1;
    }

    public function fail(int $id, string $message): void
    {
        $this->db->run(
            "UPDATE edital_extractions SET status = 'failed', error = ?, updated_at = ? WHERE id = ? AND status IN ('queued', 'running')",
            [mb_substr($message, 0, 255), $this->clock->nowDb(), $id]
        );
    }

    /** Encerra a revisão (aplicada ou descartada). Só uma vez: falso se outra pessoa já concluiu. */
    public function review(int $id, string $status, int $userId, ?string $note): bool
    {
        $now = $this->clock->nowDb();
        return $this->db->affected(
            "UPDATE edital_extractions SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ?, updated_at = ? WHERE id = ? AND status = 'ready'",
            [$status, $userId, $now, $note !== null ? mb_substr($note, 0, 500) : null, $now, $id]
        ) === 1;
    }

    /** @param array<string,mixed> $proposal */
    private static function encode(array $proposal): string
    {
        return (string) json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
