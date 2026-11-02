<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Core\Config;
use Concursei\Core\Console\Console;
use Concursei\Core\Container;
use Concursei\Modules\Catalog\AdminContestController;
use Concursei\Modules\Catalog\AdminItemsController;
use Concursei\Modules\Catalog\AdminReferenceController;
use Concursei\Modules\Catalog\SearchIndex;

return static function (App $app, Container $c, Config $config): void {
    $r = $app->router();

    // Administração (equipe com MFA; ver RequirePermission).
    $staff = static fn (string $permission): array => ['middleware' => ['auth', 'can:' . $permission, 'throttle:admin']];
    $r->get('/admin', [AdminContestController::class, 'dashboard'], ['name' => 'admin'] + $staff('admin.access'));
    $r->get('/admin/concursos', [AdminContestController::class, 'index'], $staff('contests.edit'));
    $r->get('/admin/concursos/novo', [AdminContestController::class, 'create'], $staff('contests.edit'));
    $r->post('/admin/concursos', [AdminContestController::class, 'store'], $staff('contests.edit'));
    $r->get('/admin/concursos/{id:\d+}', [AdminContestController::class, 'edit'], $staff('contests.edit'));
    $r->post('/admin/concursos/{id:\d+}', [AdminContestController::class, 'update'], $staff('contests.edit'));
    $r->delete('/admin/concursos/{id:\d+}', [AdminContestController::class, 'destroy'], $staff('contests.edit'));
    $r->post('/admin/concursos/{id:\d+}/revisao', [AdminContestController::class, 'submit'], $staff('contests.edit'));
    $r->post('/admin/concursos/{id:\d+}/devolver', [AdminContestController::class, 'returnToDraft'], $staff('contests.review'));
    $r->post('/admin/concursos/{id:\d+}/publicar', [AdminContestController::class, 'publish'], $staff('contests.publish'));
    $r->post('/admin/concursos/{id:\d+}/arquivar', [AdminContestController::class, 'archive'], $staff('contests.publish'));
    $r->post('/admin/concursos/{id:\d+}/reativar', [AdminContestController::class, 'unarchive'], $staff('contests.publish'));

    $r->get('/admin/concursos/{id:\d+}/cargos/novo', [AdminItemsController::class, 'newOffer'], $staff('contests.edit'));
    $r->post('/admin/concursos/{id:\d+}/cargos', [AdminItemsController::class, 'saveOffer'], $staff('contests.edit'));
    $r->get('/admin/concursos/{id:\d+}/cargos/{item:\d+}', [AdminItemsController::class, 'editOffer'], $staff('contests.edit'));
    $r->post('/admin/concursos/{id:\d+}/cargos/{item:\d+}', [AdminItemsController::class, 'saveOffer'], $staff('contests.edit'));
    $r->delete('/admin/concursos/{id:\d+}/cargos/{item:\d+}', [AdminItemsController::class, 'deleteOffer'], $staff('contests.edit'));
    $r->get('/admin/concursos/{id:\d+}/datas/nova', [AdminItemsController::class, 'newEvent'], $staff('contests.edit'));
    $r->post('/admin/concursos/{id:\d+}/datas', [AdminItemsController::class, 'saveEvent'], $staff('contests.edit'));
    $r->get('/admin/concursos/{id:\d+}/datas/{item:\d+}', [AdminItemsController::class, 'editEvent'], $staff('contests.edit'));
    $r->post('/admin/concursos/{id:\d+}/datas/{item:\d+}', [AdminItemsController::class, 'saveEvent'], $staff('contests.edit'));
    $r->delete('/admin/concursos/{id:\d+}/datas/{item:\d+}', [AdminItemsController::class, 'deleteEvent'], $staff('contests.edit'));

    $kinds = '{kind:orgaos|bancas|cargos}';
    $r->get('/admin/' . $kinds, [AdminReferenceController::class, 'index'], $staff('catalog.edit'));
    $r->get('/admin/' . $kinds . '/novo', [AdminReferenceController::class, 'create'], $staff('catalog.edit'));
    $r->post('/admin/' . $kinds, [AdminReferenceController::class, 'save'], $staff('catalog.edit'));
    $r->get('/admin/' . $kinds . '/{id:\d+}', [AdminReferenceController::class, 'edit'], $staff('catalog.edit'));
    $r->post('/admin/' . $kinds . '/{id:\d+}', [AdminReferenceController::class, 'save'], $staff('catalog.edit'));

    $app->registerConsole(static function (Console $console) use ($c): void {
        $console->register('catalog:refresh', 'Recalcula fase e próximas datas dos concursos publicados (Cron diário, após 00h de Brasília).', static function (array $o, array $a, Console $io) use ($c): int {
            $io->line('Concursos atualizados: ' . $c->get(SearchIndex::class)->refreshAll());
            return 0;
        });
    });
};
