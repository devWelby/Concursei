<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

use Concursei\Core\Logging\Logger;
use Throwable;

/**
 * Executa jobs em execução curta (Cron): para ao atingir o número máximo de jobs ou o tempo-limite.
 * Não inicia job novo quando o tempo restante acabou; o progresso de cada job fica salvo na fila.
 */
final class Worker
{
    public function __construct(private Queue $queue, private JobRegistry $registry, private Logger $logger)
    {
    }

    /** @return array{completed:int,retry:int,failed:int} */
    public function run(int $maxJobs, int $maxSeconds): array
    {
        $stats = ['completed' => 0, 'retry' => 0, 'failed' => 0];
        $deadline = microtime(true) + max(1, $maxSeconds);
        for ($i = 0; $i < max(1, $maxJobs) && microtime(true) < $deadline; $i++) {
            $job = $this->queue->claim();
            if ($job === null) {
                break;
            }
            $stats[$this->execute($job)]++;
        }
        return $stats;
    }

    /** @return 'completed'|'retry'|'failed' */
    public function execute(Job $job): string
    {
        try {
            $result = $this->registry->handler($job->type)->handle($job);
            $this->queue->complete($job, $result);
            return 'completed';
        } catch (RetryLater $e) {
            return $this->queue->retryOrFail($job, $e->getMessage(), $e->afterSeconds) === 'retry' ? 'retry' : 'failed';
        } catch (JobFailed $e) {
            $this->queue->fail($job, $e->getMessage());
            return 'failed';
        } catch (Throwable $e) {
            // Erro inesperado: detalhes só no log redigido; na fila, mensagem genérica.
            $this->logger->exception($e, 'Erro inesperado em job', ['job_id' => $job->id, 'type' => $job->type]);
            return $this->queue->retryOrFail($job, 'Erro interno (' . (new \ReflectionClass($e))->getShortName() . ').') === 'retry' ? 'retry' : 'failed';
        }
    }
}
