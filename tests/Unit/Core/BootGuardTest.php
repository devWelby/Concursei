<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Core;

use Concursei\Core\BootGuard;
use Concursei\Core\Config;
use PHPUnit\Framework\TestCase;

final class BootGuardTest extends TestCase
{
    /** @param array<string,mixed> $app */
    private static function problems(array $app): array
    {
        $config = new Config([
            'app' => $app + ['env' => 'production', 'debug' => false, 'url' => 'https://www.example.com', 'key' => ''],
            'database' => ['username' => 'concursei', 'password' => bin2hex(random_bytes(8))], // qualquer valor não vazio
        ]);
        return BootGuard::check($config, dirname(__DIR__, 3));
    }

    public function testProductionRequiresOperatorIdentityForLegalPages(): void
    {
        $message = 'OPERATOR_NAME e CONTACT_EMAIL (e-mail válido) são obrigatórios em produção: aparecem em privacidade, termos e correções.';
        self::assertContains($message, self::problems(['operator_name' => '', 'contact_email' => '']));
        self::assertContains($message, self::problems(['operator_name' => 'Responsável Exemplo', 'contact_email' => 'não é e-mail']));
        self::assertNotContains($message, self::problems(['operator_name' => 'Responsável Exemplo', 'contact_email' => 'contato@example.com']));
    }

    public function testProductionRejectsDebugAndPlainHttp(): void
    {
        $problems = self::problems(['debug' => true, 'url' => 'http://www.example.com', 'operator_name' => 'X', 'contact_email' => 'contato@example.com']);
        self::assertContains('APP_DEBUG deve ser false em produção.', $problems);
        self::assertContains('APP_URL deve usar https em produção.', $problems);
        self::assertContains('APP_KEY ausente ou fraca (gere com: php bin/console key:generate).', $problems);
    }
}
