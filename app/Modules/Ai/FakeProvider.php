<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

/**
 * Provedor falso SOMENTE para testes automatizados (APP_ENV=testing): devolve respostas preparadas.
 * Sem resposta preparada, falha como "não configurado" — nunca inventa conteúdo.
 */
final class FakeProvider implements AiProvider
{
    /** @var list<AiResponse|AiError> */
    private array $queue = [];
    /** @var list<AiRequest> */
    public array $requests = [];

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @param array<string,mixed> $data */
    public function willReturn(array $data, int $totalTokens = 1000): void
    {
        $this->queue[] = new AiResponse($data, 'fake-model', 'STOP', (int) ($totalTokens * 0.8), (int) ($totalTokens * 0.2), $totalTokens);
    }

    public function willFail(AiError $error): void
    {
        $this->queue[] = $error;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? new AiError('config', false);
        if ($next instanceof AiError) {
            throw $next;
        }
        return $next;
    }
}
