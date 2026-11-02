<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

/** IA desligada: toda chamada falha como "não configurada", sem simular sucesso. */
final class DisabledProvider implements AiProvider
{
    public function name(): string
    {
        return 'disabled';
    }

    public function model(): string
    {
        return '';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function generate(AiRequest $request): AiResponse
    {
        throw new AiError('config', false);
    }
}
