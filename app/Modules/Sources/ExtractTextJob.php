<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

use Concursei\Modules\Jobs\Job;
use Concursei\Modules\Jobs\JobFailed;
use Concursei\Modules\Jobs\JobHandler;

/** document.extract_text {document_id}: idempotente (documento já processado é ignorado). */
final class ExtractTextJob implements JobHandler
{
    public function __construct(private SourceRepository $sources, private DocumentStore $store, private TextExtractor $extractor)
    {
    }

    public function handle(Job $job): ?array
    {
        $doc = $this->sources->findDocument($job->int('document_id')) ?? throw new JobFailed('Documento inexistente.');
        if ($doc['text_status'] !== 'pending') {
            return ['skipped' => $doc['text_status']];
        }
        $result = $this->extractor->extract($this->store->read((string) $doc['storage_path']), (string) $doc['mime_type']);
        $this->sources->saveText((int) $doc['id'], $result);
        return ['status' => $result->status, 'pages' => $result->pages, 'chars' => $result->text !== null ? mb_strlen($result->text) : 0];
    }
}
