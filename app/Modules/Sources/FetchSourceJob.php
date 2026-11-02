<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

use Concursei\Modules\Jobs\Job;
use Concursei\Modules\Jobs\JobHandler;

/** source.fetch {source_id}: verifica a fonte (condicional) e encadeia download/extração. */
final class FetchSourceJob implements JobHandler
{
    public function __construct(private Collector $collector)
    {
    }

    public function handle(Job $job): ?array
    {
        return $this->collector->check($job->int('source_id'));
    }
}
