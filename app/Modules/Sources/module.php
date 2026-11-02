<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Core\Config;
use Concursei\Core\Console\Console;
use Concursei\Core\Container;
use Concursei\Modules\Jobs\JobRegistry;
use Concursei\Modules\Jobs\Queue;
use Concursei\Modules\Sources\AdminSourceController;
use Concursei\Modules\Sources\DocumentStore;
use Concursei\Modules\Sources\ExtractTextJob;
use Concursei\Modules\Sources\FetchDocumentJob;
use Concursei\Modules\Sources\FetchSourceJob;
use Concursei\Modules\Sources\SourceRepository;

return static function (App $app, Container $c, Config $config): void {
    $c->singleton(DocumentStore::class, static fn (): DocumentStore => new DocumentStore($app->basePath . '/storage/private/sources'));

    $jobs = $c->get(JobRegistry::class);
    $jobs->register('source.fetch', FetchSourceJob::class);
    $jobs->register('source.fetch_document', FetchDocumentJob::class);
    $jobs->register('document.extract_text', ExtractTextJob::class);

    $r = $app->router();
    $staff = ['middleware' => ['auth', 'can:sources.manage', 'throttle:admin']];
    $r->get('/admin/fontes', [AdminSourceController::class, 'index'], $staff);
    $r->get('/admin/fontes/nova', [AdminSourceController::class, 'create'], $staff);
    $r->post('/admin/fontes', [AdminSourceController::class, 'save'], $staff);
    $r->get('/admin/fontes/{id:\d+}', [AdminSourceController::class, 'edit'], $staff);
    $r->post('/admin/fontes/{id:\d+}', [AdminSourceController::class, 'save'], $staff);
    $r->post('/admin/fontes/{id:\d+}/verificar', [AdminSourceController::class, 'check'], $staff);
    $r->get('/admin/documentos/{id:\d+}', [AdminSourceController::class, 'document'], $staff);
    $r->get('/admin/documentos/{id:\d+}/arquivo', [AdminSourceController::class, 'download'], $staff);

    $app->registerConsole(static function (Console $console) use ($c): void {
        $console->register('sources:dispatch-due', 'Enfileira a verificação das fontes vencidas (Cron). --limit=N', static function (array $o, array $a, Console $io) use ($c): int {
            $queue = $c->get(Queue::class);
            $ids = $c->get(SourceRepository::class)->claimDue(isset($o['limit']) ? max(1, (int) $o['limit']) : 20);
            foreach ($ids as $id) {
                $queue->dispatch('source.fetch', ['source_id' => $id], 'source.fetch:' . $id);
            }
            $io->line('Fontes enfileiradas: ' . count($ids));
            return 0;
        });
    });
};
