<?php

declare(strict_types=1);

namespace Concursei\Modules\Extraction;

use Concursei\Modules\Ai\AiError;
use Concursei\Modules\Ai\AiGateway;
use Concursei\Modules\Jobs\Job;
use Concursei\Modules\Jobs\JobFailed;
use Concursei\Modules\Jobs\JobHandler;
use Concursei\Modules\Jobs\RetryLater;
use Concursei\Modules\Sources\SourceRepository;
use Throwable;

/**
 * edital.extract {extraction_id}: chama a IA e guarda só a proposta validada contra o texto do documento.
 * Falha temporária da IA → reagenda (respeitando Retry-After); na última tentativa, a proposta fica "falhou".
 */
final class ExtractEditalJob implements JobHandler
{
    public function __construct(
        private ExtractionRepository $extractions,
        private ExtractionService $service,
        private SourceRepository $sources,
        private AiGateway $ai,
        private ProposalValidator $validator,
    ) {
    }

    public function handle(Job $job): ?array
    {
        $id = $job->int('extraction_id');
        $x = $this->extractions->find($id) ?? throw new JobFailed('Extração inexistente.');
        if (!$this->extractions->markRunning($id)) {
            return ['skipped' => $x['status']];
        }
        try {
            return $this->run($id, $x, $job);
        } catch (RetryLater | JobFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($job->attempts >= $job->maxAttempts) {
                $this->extractions->fail($id, 'Erro interno ao processar a extração.');
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $x @return array<string,mixed> */
    private function run(int $id, array $x, Job $job): array
    {
        $doc = $this->sources->findDocument((int) $x['document_id']);
        if ($doc === null || $doc['text_status'] !== 'ready' || !is_string($doc['text'])) {
            $this->extractions->fail($id, 'Documento sem texto extraído.');
            throw new JobFailed('Documento sem texto extraído.');
        }
        ['request' => $request, 'partial' => $partial] = $this->service->aiRequest($doc['text']);

        $cached = $this->extractions->cached($request->inputHash(), $id);
        if ($cached !== null) {
            $this->extractions->ready($id, $cached['proposal'], $cached['model'], $request->inputHash(), $cached['id']);
            return ['cached_from' => $cached['id']];
        }
        try {
            $response = $this->ai->call($request, $x['requested_by'] !== null ? (int) $x['requested_by'] : null);
        } catch (AiError $e) {
            if ($e->retryable && $job->attempts < $job->maxAttempts) {
                $this->extractions->waitRetry($id, $e->getMessage() . ' Nova tentativa automática.');
                throw new RetryLater($e->getMessage(), $e->retryAfterSeconds);
            }
            $this->extractions->fail($id, $e->getMessage());
            throw new JobFailed($e->getMessage());
        }
        $proposal = $this->validator->validate($response->data, new EvidenceMatcher($doc['text']), $partial);
        $this->extractions->ready($id, $proposal, $response->model, $request->inputHash());
        $valid = count(array_filter($proposal['items'], static fn (array $i): bool => $i['valid']));
        return ['items' => count($proposal['items']), 'valid' => $valid, 'tokens' => $response->totalTokens];
    }
}
