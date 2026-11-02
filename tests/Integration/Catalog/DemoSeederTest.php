<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Catalog;

use Concursei\Core\Config;
use Concursei\Modules\Auth\UserRepository;
use Concursei\Modules\Catalog\ContestRepository;
use Concursei\Modules\Catalog\DemoSeeder;
use Concursei\Modules\Catalog\LocalDate;
use Concursei\Modules\Catalog\PublicationService;
use Concursei\Modules\Catalog\ReferenceRepository;
use Concursei\Shared\Auth\AuthUser;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use DateTimeImmutable;

final class DemoSeederTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    public function testSeedsFictionalContestsCoveringSeveralPhasesOnce(): void
    {
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:00:00'));
        $seeder = $this->app->container->get(DemoSeeder::class);
        self::assertSame(4, $seeder->run($this->staff()));
        self::assertSame(0, $seeder->run($this->staff('outra@example.com')), 'Segunda execução não duplica');

        $phases = $this->db->all('SELECT slug, phase FROM contest_search ORDER BY slug');
        self::assertSame([
            ['slug' => 'demo-fazenda-exemplo', 'phase' => 'authorized'],
            ['slug' => 'demo-instituto-exemplo', 'phase' => 'exam_done'],
            ['slug' => 'demo-policia-exemplo', 'phase' => 'edital_published'],
            ['slug' => 'demo-tribunal-exemplo', 'phase' => 'registration_open'],
        ], $phases);
        self::assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM contests WHERE title NOT LIKE '[Demonstração]%' OR official_url NOT LIKE 'https://example.com/%'"));
        self::assertStringContainsString('Nenhuma informação desta página corresponde a um concurso real', $this->request('GET', '/concursos/demo-tribunal-exemplo')->body());
    }

    public function testRefusesOutsideLocalAndForNonStaff(): void
    {
        $c = $this->app->container;
        $production = new DemoSeeder(new Config(['app' => ['env' => 'production']]), $c->get(ReferenceRepository::class), $c->get(ContestRepository::class), $c->get(PublicationService::class), $c->get(LocalDate::class));
        try {
            $production->run($this->staff());
            self::fail('Esperada recusa em produção');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('APP_ENV=local', $e->getMessage());
        }

        $student = AuthUser::fromRow($c->get(UserRepository::class)->findById($this->createUser()));
        $this->expectExceptionMessage('equipe');
        $c->get(DemoSeeder::class)->run($student);
    }
}
