<?php

declare(strict_types=1);

namespace Concursei\Core\Console;

use Concursei\Core\BootGuard;
use Concursei\Core\Database\Database;
use Concursei\Core\Database\Migrator;
use Concursei\Core\Security\Crypto;
use Concursei\Core\Security\RateLimiter;
use Concursei\Core\Session\DatabaseSessionHandler;

/**
 * Comandos de infraestrutura: migrações, chave, diagnóstico e limpezas.
 */
final class CoreCommands
{
    public static function register(Console $console): void
    {
        $app = $console->app();
        $c = $app->container;

        $console->register('migrate', 'Aplica migrações pendentes (serial, com lock). Faça backup antes em produção.', static function (array $o, array $a, Console $io) use ($c, $app): int {
            $migrator = new Migrator($c->get(Database::class), $app->basePath . '/database/migrations');
            $done = $migrator->migrate(static fn (string $m) => $io->line($m));
            $io->line($done === [] ? 'Nada a migrar.' : 'Aplicadas: ' . implode(', ', $done));
            return 0;
        });

        $console->register('migrate:status', 'Lista migrações aplicadas e pendentes.', static function (array $o, array $a, Console $io) use ($c, $app): int {
            $migrator = new Migrator($c->get(Database::class), $app->basePath . '/database/migrations');
            $applied = $migrator->applied();
            foreach ($migrator->available() as $v => $m) {
                $state = isset($applied[$v]) ? ($applied[$v]['checksum'] === $m['checksum'] ? 'aplicada' : 'ALTERADA') : 'pendente';
                $io->line(sprintf('%04d_%-40s %s', $v, $m['name'], $state));
            }
            return 0;
        });

        $console->register('key:generate', 'Gera APP_KEY. Use --write para gravar no .env se ainda não existir.', static function (array $o, array $a, Console $io) use ($app): int {
            $key = Crypto::generateKey();
            if (!isset($o['write'])) {
                $io->line('Adicione ao .env privado (não versionado):');
                $io->line('APP_KEY=' . $key);
                return 0;
            }
            $envFile = getenv('CONCURSEI_ENV_FILE') ?: $app->basePath . '/.env';
            $content = is_file($envFile) ? (string) file_get_contents($envFile) : '';
            if (preg_match('/^APP_KEY=\S+/m', $content)) {
                $io->error('APP_KEY já definida. Trocar a chave invalida segredos cifrados (MFA) — operação manual e planejada.');
                return 1;
            }
            $content = preg_match('/^APP_KEY=\s*$/m', $content)
                ? (string) preg_replace('/^APP_KEY=\s*$/m', 'APP_KEY=' . $key, $content)
                : rtrim($content) . "\nAPP_KEY=" . $key . "\n";
            file_put_contents($envFile, $content, LOCK_EX);
            @chmod($envFile, 0600);
            $io->line('APP_KEY gravada em ' . basename($envFile) . '.');
            return 0;
        });

        $console->register('app:check', 'Diagnóstico de configuração e ambiente (sem exibir segredos).', static function (array $o, array $a, Console $io) use ($c, $app): int {
            $problems = BootGuard::check($app->config, $app->basePath);
            $io->line('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), ambiente: ' . $app->config->string('app.env'));
            try {
                $db = $c->get(Database::class);
                $io->line('Banco: ' . $db->scalar('SELECT VERSION()'));
            } catch (\Throwable $e) {
                $problems[] = 'Banco de dados inacessível com as credenciais configuradas.';
            }
            foreach ($problems as $p) {
                $io->error('- ' . $p);
            }
            $io->line($problems === [] ? 'OK: nenhuma pendência encontrada.' : count($problems) . ' pendência(s).');
            return $problems === [] ? 0 : 1;
        });

        $console->register('session:gc', 'Remove sessões expiradas.', static function (array $o, array $a, Console $io) use ($c): int {
            $n = $c->get(DatabaseSessionHandler::class)->gc(0);
            $io->line("Sessões removidas: {$n}");
            return 0;
        });

        $console->register('ratelimit:purge', 'Remove janelas de rate limit expiradas.', static function (array $o, array $a, Console $io) use ($c): int {
            $io->line('Registros removidos: ' . $c->get(RateLimiter::class)->purgeExpired());
            return 0;
        });
    }
}
