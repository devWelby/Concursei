<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Modules\Home\HomeController;

return static function (App $app): void {
    $r = $app->router();
    $r->get('/', [HomeController::class, 'index'], ['name' => 'home', 'cache' => 'public']);
    // Verificação mínima de disponibilidade (sem detalhes internos).
    $r->get('/saude', [HomeController::class, 'health'], ['name' => 'health']);
};
