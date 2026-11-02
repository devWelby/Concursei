<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

final class RawResponse
{
    /** @param array<string,string> $headers nomes em minúsculas (último valor) */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $connectedIp,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function mimeType(): string
    {
        return strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
    }
}
