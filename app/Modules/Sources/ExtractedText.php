<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

/** Resultado da extração de texto de um documento coletado. */
final class ExtractedText
{
    /** @param 'ready'|'empty'|'unsupported'|'failed' $status */
    public function __construct(
        public readonly string $status,
        public readonly ?string $text = null,
        public readonly ?int $pages = null,
        public readonly ?string $title = null,
        public readonly ?string $note = null,
    ) {
    }
}
