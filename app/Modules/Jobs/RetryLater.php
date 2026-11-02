<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

use RuntimeException;

/** Falha temporária: reagenda com backoff (ou após o tempo pedido, ex.: Retry-After). */
final class RetryLater extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $afterSeconds = null)
    {
        parent::__construct($message);
    }
}
