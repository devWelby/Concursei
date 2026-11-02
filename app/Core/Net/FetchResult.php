<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

final class FetchResult
{
    /** @param list<string> $redirects URLs percorridas antes da final */
    public function __construct(
        public readonly int $status,
        public readonly string $url,
        public readonly string $body,
        public readonly string $mimeType,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
        public readonly array $redirects,
    ) {
    }

    public function notModified(): bool
    {
        return $this->status === 304;
    }
}
