<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Core;

use Concursei\Core\Database\Migrator;
use Concursei\Core\Security\RateLimiter;
use Concursei\Core\Session\DatabaseSessionHandler;
use Concursei\Tests\Support\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

final class InfrastructureTest extends IntegrationTestCase
{
    public function testMigrationsAreIdempotentAndChecksummed(): void
    {
        $migrator = new Migrator($this->db, $this->app->basePath . '/database/migrations');
        self::assertSame([], $migrator->migrate(), 'Segunda execução não reaplica nada');
        $applied = $migrator->applied();
        self::assertArrayHasKey(1, $applied);
        self::assertSame(64, strlen($applied[1]['checksum']));
    }

    public function testChangedMigrationIsDetected(): void
    {
        $tmp = sys_get_temp_dir() . '/concursei-mig-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        copy($this->app->basePath . '/database/migrations/0001_core_infrastructure.sql', $tmp . '/0001_core_infrastructure.sql');
        file_put_contents($tmp . '/0001_core_infrastructure.sql', "\n-- alterada", FILE_APPEND);
        try {
            (new Migrator($this->db, $tmp))->migrate();
            self::fail('Checksum divergente deveria falhar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('checksum divergente', $e->getMessage());
        } finally {
            array_map('unlink', glob($tmp . '/*') ?: []);
            rmdir($tmp);
        }
    }

    public function testDatabaseRunsInStrictModeAndUtc(): void
    {
        $mode = (string) $this->db->scalar('SELECT @@SESSION.sql_mode');
        self::assertStringContainsString('STRICT_ALL_TABLES', $mode);
        self::assertSame('+00:00', $this->db->scalar('SELECT @@SESSION.time_zone'));
    }

    public function testRateLimiterCountsAndResetsWindow(): void
    {
        $this->clock->setNow(new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('UTC')));
        $rl = $this->app->container->get(RateLimiter::class);
        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue($rl->hit('login', 'ip:203.0.113.1', 3, 60)['allowed']);
        }
        $blocked = $rl->hit('login', 'ip:203.0.113.1', 3, 60);
        self::assertFalse($blocked['allowed']);
        self::assertGreaterThan(0, $blocked['retry_after']);
        self::assertTrue($rl->hit('login', 'ip:203.0.113.2', 3, 60)['allowed'], 'Outro IP tem balde próprio');

        $this->clock->setNow(new DateTimeImmutable('2026-11-02 12:01:01', new DateTimeZone('UTC')));
        self::assertTrue($rl->hit('login', 'ip:203.0.113.1', 3, 60)['allowed'], 'Nova janela zera a contagem');
        self::assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM rate_limits WHERE scope = 'login' AND bucket LIKE '%203.0.113%'"), 'IP não é armazenado em claro');
    }

    public function testSessionHandlerStoresOnlyHashAndDestroysPerUser(): void
    {
        $handler = $this->app->container->get(DatabaseSessionHandler::class);
        $idA = $handler->create_sid();
        $idB = $handler->create_sid();
        $handler->setUserId(42);
        $handler->read($idA);
        $handler->write($idA, 'a|s:1:"x";');
        $handler->close();
        $handler->read($idB);
        $handler->write($idB, 'b|s:1:"y";');
        $handler->close();

        self::assertTrue($handler->validateId($idA));
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [$idA]), 'ID em claro não é chave');
        self::assertSame('a|s:1:"x";', $handler->read($idA));
        $handler->close();

        self::assertSame(1, $handler->destroyForUser(42, $idA), 'Logout global preserva a sessão atual');
        self::assertFalse($handler->validateId($idB));
        self::assertTrue($handler->validateId($idA));
        self::assertFalse($handler->validateId('../../etc/passwd'));
    }
}
