<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

interface AiProvider
{
    public function name(): string;

    public function model(): string;

    public function isConfigured(): bool;

    /** @throws AiError */
    public function generate(AiRequest $request): AiResponse;
}
