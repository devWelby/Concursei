<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Core\Config;
use Concursei\Core\Container;
use Concursei\Modules\Extraction\AdminExtractionController;
use Concursei\Modules\Extraction\ExtractEditalJob;
use Concursei\Modules\Jobs\JobRegistry;

return static function (App $app, Container $c, Config $config): void {
    $c->get(JobRegistry::class)->register('edital.extract', ExtractEditalJob::class);

    $r = $app->router();
    // Pedir extração gasta orçamento de IA: quem gerencia fontes. Revisar/aplicar altera o concurso: quem edita concursos.
    $request = ['middleware' => ['auth', 'can:sources.manage', 'throttle:admin']];
    $review = ['middleware' => ['auth', 'can:contests.edit', 'throttle:admin']];
    $r->get('/admin/documentos/{id:\d+}/extrair', [AdminExtractionController::class, 'form'], $request);
    $r->post('/admin/documentos/{id:\d+}/extrair', [AdminExtractionController::class, 'request'], $request);
    $r->get('/admin/extracoes', [AdminExtractionController::class, 'index'], $review);
    $r->get('/admin/extracoes/{id:\d+}', [AdminExtractionController::class, 'show'], $review);
    $r->post('/admin/extracoes/{id:\d+}/aplicar', [AdminExtractionController::class, 'apply'], $review);
    $r->post('/admin/extracoes/{id:\d+}/descartar', [AdminExtractionController::class, 'reject'], $review);
};
