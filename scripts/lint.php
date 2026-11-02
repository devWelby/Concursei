<?php

declare(strict_types=1);

/**
 * Verifica a sintaxe de todos os arquivos PHP do projeto (exceto vendor/).
 * Uso: php scripts/lint.php
 */

$root = dirname(__DIR__);
$dirs = ['app', 'bootstrap', 'config', 'public', 'resources', 'scripts', 'tests', 'database'];
$files = is_file($root . '/bin/console') ? [$root . '/bin/console'] : [];
foreach ($dirs as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
$failed = 0;
foreach ($files as $file) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failed++;
        echo implode(PHP_EOL, $out), PHP_EOL;
    }
}
echo sprintf('%d arquivo(s) verificados, %d com erro.%s', count($files), $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
