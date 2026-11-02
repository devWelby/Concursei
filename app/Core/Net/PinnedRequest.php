<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

/** Requisição GET já validada, com o endereço IP fixado (impede DNS rebinding entre validação e conexão). */
final class PinnedRequest
{
    /**
     * @param array<string,string> $headers
     * @param list<string> $acceptTypes tipos MIME aceitos em respostas 2xx
     */
    public function __construct(
        public readonly SafeUrl $target,
        public readonly string $ip,
        public readonly array $headers,
        public readonly array $acceptTypes,
        public readonly int $maxBytes,
        public readonly int $connectTimeout,
        public readonly int $timeout,
    ) {
    }
}
