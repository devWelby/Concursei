<?php

declare(strict_types=1);

/**
 * Registro explícito dos módulos (ordem importa: Mail antes dos módulos que enviam e-mail).
 * Cada entrada é callable(App, Container, Config): void.
 */
return [
    require __DIR__ . '/Modules/Mail/module.php',
    require __DIR__ . '/Modules/Home/module.php',
];
