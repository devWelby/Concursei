<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Modules\Pages\PagesController;

return static function (App $app): void {
    $r = $app->router();
    $r->get('/sobre', [PagesController::class, 'about'], ['name' => 'about', 'cache' => 'public']);
    $r->get('/privacidade', [PagesController::class, 'privacy'], ['name' => 'privacy', 'cache' => 'public']);
    $r->get('/termos', [PagesController::class, 'terms'], ['name' => 'terms', 'cache' => 'public']);
    $r->get('/cookies', [PagesController::class, 'cookies'], ['name' => 'cookies', 'cache' => 'public']);
    $r->get('/correcoes', [PagesController::class, 'corrections'], ['name' => 'corrections', 'cache' => 'public']);
};
