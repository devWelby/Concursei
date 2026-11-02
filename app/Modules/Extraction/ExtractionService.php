<?php

declare(strict_types=1);

namespace Concursei\Modules\Extraction;

use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Modules\Ai\AiGateway;
use Concursei\Modules\Catalog\ContestRepository;
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\StaleVersion;
use Concursei\Modules\Catalog\WorkflowViolation;
use Concursei\Modules\Jobs\Queue;
use Concursei\Modules\Sources\SourceRepository;
use Concursei\Shared\Audit\AuditLog;

/**
 * Extração assistida (D-019): pedido → fila → proposta validada → revisão humana → aplicação na cópia
 * de trabalho como rascunho. Nunca publica, nunca marca o concurso como conferido e nunca aplica
 * item bloqueado ou vindo de fonte secundária.
 */
final class ExtractionService
{
    private const OFFER_COMPARE = ['education_level', 'vacancies', 'reserve', 'salary_cents', 'workload_hours', 'requirements'];

    public function __construct(
        private ExtractionRepository $extractions,
        private SourceRepository $sources,
        private ContestRepository $contests,
        private Queue $queue,
        private AiGateway $ai,
        private Config $config,
        private Database $db,
        private AuditLog $audit,
    ) {
    }

    /**
     * @return array{id:int,outcome:'queued'|'cached'|'existing'}
     * @throws ExtractionRefused
     */
    public function request(int $documentId, int $contestId, int $userId): array
    {
        $doc = $this->sources->findDocument($documentId) ?? throw new ExtractionRefused('Documento não encontrado.');
        if ($doc['text_status'] !== 'ready' || !is_string($doc['text'])) {
            throw new ExtractionRefused('O documento não tem texto extraído. Revise o arquivo manualmente.');
        }
        $contest = $this->contests->find($contestId);
        if ($contest === null || $contest['archived_at'] !== null) {
            throw new ExtractionRefused('Selecione um concurso ativo.');
        }
        $active = $this->extractions->active($documentId, $contestId);
        if ($active !== null) {
            return ['id' => $active, 'outcome' => 'existing'];
        }

        $request = $this->aiRequest((string) $doc['text'])['request'];
        $cached = $this->extractions->cached($request->inputHash());
        if ($cached !== null) {
            $id = $this->extractions->create([
                'document_id' => $documentId, 'contest_id' => $contestId, 'input_sha256' => $request->inputHash(), 'requested_by' => $userId,
                'status' => 'ready', 'result' => $cached['proposal'], 'model' => $cached['model'], 'reused_from' => $cached['id'],
            ]);
            $this->audit->record($userId, 'extraction.request', 'edital_extraction', $id, ['document_id' => $documentId, 'contest_id' => $contestId, 'cached' => true]);
            return ['id' => $id, 'outcome' => 'cached'];
        }
        if (!$this->ai->isAvailable()) {
            throw new ExtractionRefused('IA não configurada neste servidor (GEMINI_API_KEY e GEMINI_MODEL no .env). Cadastre os dados manualmente.');
        }
        $id = $this->extractions->create([
            'document_id' => $documentId, 'contest_id' => $contestId, 'input_sha256' => $request->inputHash(), 'requested_by' => $userId,
        ]);
        $this->queue->dispatch('edital.extract', ['extraction_id' => $id], 'edital.extract:' . $id, $userId, 0, 3);
        $this->audit->record($userId, 'extraction.request', 'edital_extraction', $id, ['document_id' => $documentId, 'contest_id' => $contestId, 'cached' => false]);
        return ['id' => $id, 'outcome' => 'queued'];
    }

    /** @return array{request:\Concursei\Modules\Ai\AiRequest,partial:bool} */
    public function aiRequest(string $documentText): array
    {
        $prepared = EditalPrompt::prepare($documentText, max(10000, $this->config->int('ai.edital_max_input_chars', 120000)));
        return [
            'request' => EditalPrompt::request($prepared['text'], max(1024, $this->config->int('ai.edital_max_output_tokens', 8192))),
            'partial' => $prepared['partial'],
        ];
    }

    /**
     * Situação de cada item frente à cópia de trabalho ATUAL do concurso.
     * @param array<string,mixed> $proposal
     * @param array<string,mixed> $contest
     * @return array<string,array{status:'new'|'same'|'changed'|'blocked',target:?int,current:?string,changes:list<string>}>
     */
    public function compare(array $proposal, array $contest): array
    {
        $events = $this->contests->events((int) $contest['id']);
        $offers = $this->contests->offers((int) $contest['id']);
        $out = [];
        foreach ($proposal['items'] ?? [] as $item) {
            $key = (string) $item['key'];
            if (!$item['valid']) {
                $out[$key] = ['status' => 'blocked', 'target' => null, 'current' => null, 'changes' => []];
                continue;
            }
            $out[$key] = match ($item['kind']) {
                'fee' => $this->compareFee($item['value'], $contest),
                'event' => $this->compareEvent($item['value'], $events),
                default => $this->compareOffer($item['value'], $offers),
            };
        }
        return $out;
    }

    /**
     * Aplica os itens escolhidos numa única edição do rascunho (verificação de versão = tela que a pessoa revisou).
     * @param list<string> $keys
     * @return array{events:int,offers:int,fee:bool,source:bool}
     * @throws ExtractionRefused|StaleVersion|WorkflowViolation
     */
    public function apply(int $id, int $expectedVersion, array $keys, bool $useAsSource, int $userId): array
    {
        $x = $this->reviewable($id);
        if ($x['source_kind'] !== 'official') {
            throw new ExtractionRefused('Fonte secundária não altera o concurso: localize o documento oficial e extraia dele.');
        }
        $contest = $this->contests->find((int) $x['contest_id']) ?? throw new ExtractionRefused('O concurso desta proposta não existe mais.');
        $diff = $this->compare($x['proposal'], $contest);
        $selected = array_flip($keys);

        $fees = [];
        $events = [];
        $offers = [];
        $nextOrder = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) FROM contest_offers WHERE contest_id = ?', [$contest['id']]);
        foreach ($x['proposal']['items'] as $item) {
            $d = $diff[$item['key']] ?? null;
            if (!isset($selected[$item['key']]) || $d === null || !in_array($d['status'], ['new', 'changed'], true)) {
                continue;
            }
            $v = $item['value'];
            if ($item['kind'] === 'fee') {
                $fees[] = $v;
            } elseif ($item['kind'] === 'event') {
                $events[] = $d['status'] === 'new'
                    ? [null, ['type' => $v['type'], 'label' => $v['label'], 'date_value' => $v['date_value'], 'date_precision' => $v['date_precision'], 'time_local' => $v['time_local'], 'note' => null]]
                    : [$d['target'], ['date_value' => $v['date_value'], 'date_precision' => $v['date_precision'], 'time_local' => $v['time_local']]];
            } elseif ($d['status'] === 'new') {
                $offers[] = [null, $v + ['career_id' => null, 'salary_note' => null, 'location' => null, 'sort_order' => min(999, ++$nextOrder)]];
            } else {
                // Só os campos que a proposta informa e que mudaram: nada é apagado por ausência na resposta.
                $offers[] = [$d['target'], array_intersect_key($v, array_flip($d['changes']))];
            }
        }

        $contestData = [];
        if ($fees !== []) {
            $amounts = array_column($fees, 'fee_cents');
            $contestData['fee_cents'] = min($amounts);
            if (count($fees) > 1) {
                $contestData['fee_note'] = mb_substr(implode('; ', array_map(
                    static fn (array $f): string => Format::money($f['fee_cents']) . ($f['applies_to'] !== null ? ' (' . $f['applies_to'] . ')' : ''),
                    $fees
                )), 0, 500);
            }
        }
        if ($useAsSource) {
            $contestData['source_url'] = (string) $x['document_url'];
        }
        if ($contestData === [] && $events === [] && $offers === []) {
            throw new ExtractionRefused('Selecione ao menos um item novo ou alterado.');
        }

        $this->db->transaction(function () use ($id, $contest, $expectedVersion, $contestData, $events, $offers, $userId): void {
            $this->contests->saveBatch((int) $contest['id'], $expectedVersion, $contestData, $events, $offers, $userId);
            if (!$this->extractions->review($id, 'applied', $userId, null)) {
                throw new ExtractionRefused('Esta proposta já foi revisada por outra pessoa.');
            }
        });
        $summary = ['events' => count($events), 'offers' => count($offers), 'fee' => $fees !== [], 'source' => $useAsSource];
        $this->audit->record($userId, 'extraction.apply', 'edital_extraction', $id, $summary + ['contest_id' => (int) $contest['id']]);
        return $summary;
    }

    /** @throws ExtractionRefused */
    public function reject(int $id, ?string $note, int $userId): void
    {
        $this->reviewable($id);
        if (!$this->extractions->review($id, 'rejected', $userId, $note)) {
            throw new ExtractionRefused('Esta proposta já foi revisada por outra pessoa.');
        }
        $this->audit->record($userId, 'extraction.reject', 'edital_extraction', $id, []);
    }

    /** @return array<string,mixed> */
    private function reviewable(int $id): array
    {
        $x = $this->extractions->find($id) ?? throw new ExtractionRefused('Proposta não encontrada.');
        if ($x['status'] !== 'ready' || !is_array($x['proposal'])) {
            throw new ExtractionRefused('Esta proposta não está aguardando revisão.');
        }
        if ($x['contest_id'] === null) {
            throw new ExtractionRefused('O concurso desta proposta não existe mais.');
        }
        return $x;
    }

    /** @param array<string,mixed> $v @param array<string,mixed> $contest */
    private function compareFee(array $v, array $contest): array
    {
        $current = $contest['fee_cents'] !== null ? (int) $contest['fee_cents'] : null;
        $status = $current === null ? 'new' : ($current === $v['fee_cents'] ? 'same' : 'changed');
        return ['status' => $status, 'target' => null, 'current' => $current !== null ? Format::money($current) : null, 'changes' => []];
    }

    /** @param array<string,mixed> $v @param list<array<string,mixed>> $events */
    private function compareEvent(array $v, array $events): array
    {
        $candidates = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === $v['type']
            && ($v['type'] !== 'other' || EvidenceMatcher::norm((string) $e['label']) === EvidenceMatcher::norm((string) $v['label']))));
        foreach ($candidates as $e) {
            if ($e['date_value'] === $v['date_value'] && $e['date_precision'] === $v['date_precision'] && ($v['time_local'] === null || $e['time_local'] === $v['time_local'])) {
                return ['status' => 'same', 'target' => (int) $e['id'], 'current' => null, 'changes' => []];
            }
        }
        if (count($candidates) === 1) {
            $e = $candidates[0];
            return ['status' => 'changed', 'target' => (int) $e['id'], 'current' => Format::eventDate($e['date_value'], (string) $e['date_precision'], $e['time_local']), 'changes' => ['date_value']];
        }
        return ['status' => 'new', 'target' => null, 'current' => null, 'changes' => []];
    }

    /** @param array<string,mixed> $v @param list<array<string,mixed>> $offers */
    private function compareOffer(array $v, array $offers): array
    {
        $title = EvidenceMatcher::norm((string) $v['title']);
        foreach ($offers as $o) {
            if (EvidenceMatcher::norm((string) $o['title']) !== $title) {
                continue;
            }
            $changes = [];
            foreach (self::OFFER_COMPARE as $field) {
                if ($v[$field] === null || ($field === 'reserve' && $v[$field] === 0)) {
                    continue;
                }
                if ((string) $v[$field] !== (string) $o[$field]) {
                    $changes[] = $field;
                }
            }
            $current = Format::vacancies($o['vacancies'] !== null ? (int) $o['vacancies'] : null, (int) $o['reserve'] === 1)
                . ($o['salary_cents'] !== null ? ' — ' . Format::money((int) $o['salary_cents']) : '');
            return ['status' => $changes === [] ? 'same' : 'changed', 'target' => (int) $o['id'], 'current' => $current, 'changes' => $changes];
        }
        return ['status' => 'new', 'target' => null, 'current' => null, 'changes' => []];
    }
}
