<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

use RuntimeException;

/**
 * Falha da chamada de IA, com tipo estável para fila, painel e interface.
 * uncertain = a requisição pode ter sido processada e cobrada (ex.: tempo esgotado aguardando resposta).
 */
final class AiError extends RuntimeException
{
    public const MESSAGES = [
        'config' => 'IA não configurada (GEMINI_API_KEY/GEMINI_MODEL).',
        'budget' => 'Teto de uso de IA atingido para o período.',
        'circuit_open' => 'IA pausada após falhas seguidas do provedor.',
        'auth' => 'Credencial da IA recusada pelo provedor.',
        'bad_request' => 'Pedido recusado pelo provedor (modelo ou parâmetros inválidos).',
        'rate_limited' => 'Limite de requisições do provedor atingido.',
        'unavailable' => 'Provedor de IA indisponível no momento.',
        'timeout' => 'Tempo esgotado aguardando a IA.',
        'network' => 'Falha de rede ao chamar a IA.',
        'blocked' => 'Resposta bloqueada pelos filtros do provedor.',
        'truncated' => 'Resposta da IA incompleta (limite de saída atingido).',
        'invalid_json' => 'Resposta da IA não é um JSON válido.',
        'empty' => 'Resposta da IA vazia.',
    ];

    public function __construct(
        public readonly string $kind,
        public readonly bool $retryable,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $uncertain = false,
    ) {
        parent::__construct(self::MESSAGES[$kind] ?? 'Falha na chamada de IA.');
    }

    /** Falha atribuível ao provedor (conta para o circuit breaker). */
    public function countsForCircuit(): bool
    {
        return in_array($this->kind, ['auth', 'rate_limited', 'unavailable', 'timeout', 'network'], true);
    }
}
