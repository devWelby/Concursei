<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

/**
 * Handler de um tipo de job. Entrega é "pelo menos uma vez": o handler precisa ser idempotente
 * (o mesmo job pode rodar de novo se o worker morrer depois de concluir o trabalho).
 * Lança RetryLater para falha temporária e JobFailed para falha definitiva.
 */
interface JobHandler
{
    /** @return array<string,scalar|null>|null resumo pequeno exibido no painel */
    public function handle(Job $job): ?array;
}
