<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

use RuntimeException;

/** Falha definitiva: o job vai para "failed" sem novas tentativas (reprocessamento é manual). */
final class JobFailed extends RuntimeException
{
}
