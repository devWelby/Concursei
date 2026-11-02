<?php

declare(strict_types=1);

/**
 * Registro explícito dos módulos (ordem importa: Jobs primeiro — registro de tipos de job; Mail antes de Auth; Auth antes dos que exigem login).
 * Cada entrada é callable(App, Container, Config): void.
 */
return [
    require __DIR__ . '/Modules/Jobs/module.php',
    require __DIR__ . '/Modules/Ai/module.php',
    require __DIR__ . '/Modules/Mail/module.php',
    require __DIR__ . '/Modules/Auth/module.php',
    require __DIR__ . '/Modules/Catalog/module.php',
    require __DIR__ . '/Modules/Sources/module.php',
    require __DIR__ . '/Modules/Home/module.php',
];
