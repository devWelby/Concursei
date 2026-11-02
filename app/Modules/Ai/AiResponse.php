<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

/** Resposta já decodificada como objeto JSON. Conteúdo NÃO confiável até a validação de domínio no PHP. */
final class AiResponse
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly ?string $finishReason,
        public readonly ?int $promptTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $totalTokens,
    ) {
    }
}
