<?php

declare(strict_types=1);

namespace Concursei\Tests\Support;

use Concursei\Core\App;
use Concursei\Core\Clock;
use Concursei\Core\Database\Database;
use Concursei\Core\Database\Migrator;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Base para testes de integração contra MySQL/MariaDB real (banco *_test).
 * O schema é recriado uma vez por processo; as tabelas são esvaziadas a cada teste.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $schemaReady = false;
    protected App $app;
    protected Database $db;
    protected Clock $clock;

    protected function setUp(): void
    {
        if (getenv('DB_DATABASE') === false || getenv('DB_DATABASE') === '') {
            self::markTestSkipped('Banco de teste não configurado (rode scripts/dev/test-db.ps1 start ou defina DB_* no CI).');
        }
        $_SESSION = [];
        $root = dirname(__DIR__, 2);
        $this->app = (require $root . '/bootstrap/app.php')($root);
        $this->db = $this->app->container->get(Database::class);
        $this->clock = $this->app->container->get(Clock::class);
        if (!self::$schemaReady) {
            $this->resetSchema($root);
            self::$schemaReady = true;
        } else {
            $this->truncateAll();
        }
    }

    private function resetSchema(string $root): void
    {
        $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $row) {
            $this->db->run('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $row['t']) . '`');
        }
        $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($this->db, $root . '/database/migrations'))->migrate();
    }

    protected function truncateAll(): void
    {
        $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'schema_migrations'") as $row) {
            $this->db->run('DELETE FROM `' . str_replace('`', '', (string) $row['t']) . '`');
        }
        $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param array<string,mixed> $post */
    protected function request(string $method, string $path, array $post = [], array $server = [], array $cookies = [], ?string $json = null): Response
    {
        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);
        $server += ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'PHPUnit'];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $req = new Request($method, Request::normalizePath($parts['path'] ?? '/'), $query, $post, $cookies, $server, [], $json);
        return $this->app->handle($req);
    }

    /** Requisição de formulário com token CSRF válido da sessão atual. */
    protected function post(string $path, array $post = [], array $server = []): Response
    {
        $session = $this->app->container->get(\Concursei\Core\Session\Session::class);
        $session->start();
        $post['_csrf'] = $session->csrfToken();
        return $this->request('POST', $path, $post, $server);
    }
}
