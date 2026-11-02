<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

/**
 * Pedido de saída estruturada. O texto do usuário/documento vai SEPARADO das instruções do sistema:
 * instruções embutidas em documentos coletados são dado, não comando.
 */
final class AiRequest
{
    /** @param array<string,mixed> $schema schema de resposta (subconjunto OpenAPI aceito pela API) */
    public function __construct(
        public readonly string $purpose,
        public readonly string $promptVersion,
        public readonly string $system,
        public readonly string $text,
        public readonly array $schema,
        public readonly int $maxOutputTokens = 8192,
        public readonly float $temperature = 0.0,
    ) {
    }

    /** Estimativa conservadora (~3 caracteres por token) para a reserva de orçamento. */
    public function estimatedTokens(): int
    {
        return (int) ceil((mb_strlen($this->system) + mb_strlen($this->text) + strlen((string) json_encode($this->schema))) / 3) + $this->maxOutputTokens;
    }

    public function inputHash(): string
    {
        return hash('sha256', $this->promptVersion . "\0" . $this->system . "\0" . $this->text);
    }
}
