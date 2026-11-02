<?php

declare(strict_types=1);

namespace Concursei\Tests\Support;

use Concursei\Modules\Jobs\Job;
use Concursei\Modules\Jobs\JobFailed;
use Concursei\Modules\Jobs\JobHandler;
use Concursei\Modules\Jobs\RetryLater;

/** Handler de teste: o payload escolhe o desfecho ("mode"). */
final class ScriptedJobHandler implements JobHandler
{
    /** @var list<int> */
    public array $handled = [];

    public function handle(Job $job): ?array
    {
        $this->handled[] = $job->id;
        return match ($job->payload['mode'] ?? 'ok') {
            'ok' => ['echo' => (string) ($job->payload['value'] ?? '')],
            'retry' => throw new RetryLater('Fonte indisponível (HTTP 503)'),
            'retry_after' => throw new RetryLater('Limite do provedor (HTTP 429)', 120),
            'fail' => throw new JobFailed('Documento inválido'),
            default => throw new \LogicException('detalhe interno: caminho /home/conta/private'),
        };
    }
}
