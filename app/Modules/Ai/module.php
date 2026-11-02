<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Core\Config;
use Concursei\Core\Container;
use Concursei\Modules\Ai\AiProvider;
use Concursei\Modules\Ai\DisabledProvider;
use Concursei\Modules\Ai\FakeProvider;
use Concursei\Modules\Ai\GeminiProvider;

return static function (App $app, Container $c, Config $config): void {
    $c->singleton(AiProvider::class, static function () use ($config): AiProvider {
        return match ($config->string('ai.transport')) {
            'rest' => new GeminiProvider(
                $config->string('ai.api_key'),
                $config->string('ai.model'),
                $config->string('ai.endpoint', 'https://generativelanguage.googleapis.com/v1beta'),
                $config->int('ai.timeout', 90),
            ),
            // O falso só existe nos testes automatizados: fora deles não há "sucesso" simulado.
            'fake' => $config->string('app.env') === 'testing' ? new FakeProvider() : new DisabledProvider(),
            default => new DisabledProvider(),
        };
    });
};
