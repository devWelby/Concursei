<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

use Concursei\Modules\Jobs\Job;
use Concursei\Modules\Jobs\JobFailed;
use Concursei\Modules\Jobs\JobHandler;

/** source.fetch_document {source_id, url}: baixa um PDF descoberto (a URL passa de novo pela política). */
final class FetchDocumentJob implements JobHandler
{
    public function __construct(private Collector $collector)
    {
    }

    public function handle(Job $job): ?array
    {
        $url = $job->payload['url'] ?? null;
        if (!is_string($url) || $url === '' || strlen($url) > 2048) {
            throw new JobFailed('Payload inválido: url');
        }
        return $this->collector->fetchDocument($job->int('source_id'), $url);
    }
}
