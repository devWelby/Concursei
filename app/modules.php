<?php

declare(strict_types=1);

/**
 * Registro explícito dos módulos (ordem importa: Auth antes dos que exigem login).
 * Cada entrada é callable(App, Container, Config): void.
 */
return [
    require __DIR__ . '/Modules/Home/module.php',
];
