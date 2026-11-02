<?php

declare(strict_types=1);

namespace Concursei\Core\Console;

use Closure;
use Concursei\Core\App;
use Concursei\Core\Logging\Logger;
use Throwable;

/**
 * Despachante de comandos CLI. Comandos são registrados pelos módulos.
 * Nunca é acessível via HTTP (bin/console recusa SAPI diferente de cli).
 */
final class Console
{
    /** @var array<string,array{description:string,handler:Closure}> */
    private array $commands = [];

    public function __construct(private App $app)
    {
    }

    /** @param Closure(array<string,string|bool>, list<string>, Console):int $handler */
    public function register(string $name, string $description, Closure $handler): void
    {
        $this->commands[$name] = ['description' => $description, 'handler' => $handler];
    }

    public function app(): App
    {
        return $this->app;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'list';
        if ($name === 'list' || $name === '--help' || !isset($this->commands[$name])) {
            if (!in_array($name, ['list', '--help'], true)) {
                $this->error("Comando desconhecido: {$name}");
            }
            $this->line('Uso: php bin/console <comando> [--opcao=valor]');
            ksort($this->commands);
            foreach ($this->commands as $cmd => $def) {
                $this->line(sprintf('  %-28s %s', $cmd, $def['description']));
            }
            return $name === 'list' || $name === '--help' ? 0 : 1;
        }
        [$options, $args] = self::parseArgs(array_slice($argv, 2));
        $logger = $this->app->container->get(Logger::class);
        $logger->setRequestId('cli-' . bin2hex(random_bytes(4)));
        try {
            return ($this->commands[$name]['handler'])($options, $args, $this);
        } catch (Throwable $e) {
            $logger->exception($e, 'Falha em comando CLI', ['command' => $name]);
            $this->error('Falha: ' . Logger::redactString($e->getMessage()));
            return 1;
        }
    }

    /** @return array{0:array<string,string|bool>,1:list<string>} */
    public static function parseArgs(array $argv): array
    {
        $opts = [];
        $args = [];
        foreach ($argv as $a) {
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $a, $m)) {
                $opts[$m[1]] = $m[2] ?? true;
            } else {
                $args[] = $a;
            }
        }
        return [$opts, $args];
    }

    public function line(string $s): void
    {
        fwrite(STDOUT, $s . PHP_EOL);
    }

    public function error(string $s): void
    {
        fwrite(STDERR, $s . PHP_EOL);
    }

    /** Lê uma linha do STDIN (sem eco quando possível, para senhas). */
    public function ask(string $question, bool $hidden = false): string
    {
        fwrite(STDOUT, $question . ' ');
        if ($hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && posix_isatty_safe()) {
            @shell_exec('stty -echo');
            $value = fgets(STDIN);
            @shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        } else {
            $value = fgets(STDIN);
        }
        return trim((string) $value);
    }
}

function posix_isatty_safe(): bool
{
    return function_exists('posix_isatty') && @posix_isatty(STDIN);
}
