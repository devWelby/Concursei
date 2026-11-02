<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

use RuntimeException;

/** Falha de rede ou de resposta. retryable indica se uma nova tentativa (com backoff) faz sentido. */
final class FetchFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
