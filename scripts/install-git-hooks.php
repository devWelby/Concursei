<?php

declare(strict_types=1);

/**
 * Instala hooks locais (pre-commit e pre-push) que executam o verificador de
 * segurança do repositório. Hooks são auxiliares: não substituem a revisão.
 *
 * Uso: php scripts/install-git-hooks.php
 */

$root = dirname(__DIR__);
$hooksDir = $root . '/.git/hooks';
if (!is_dir($hooksDir)) {
    fwrite(STDERR, "Diretório .git/hooks não encontrado. Execute na raiz do repositório.\n");
    exit(1);
}

$hooks = [
    'pre-commit' => <<<'SH'
#!/bin/sh
# Concursei: bloqueia commit com arquivo proibido ou segredo em staging.
exec php scripts/check-repository-safety.php --staged
SH,
    'pre-push' => <<<'SH'
#!/bin/sh
# Concursei: revisa todos os commits a enviar (não só o último diff).
remote="$1"
z40=0000000000000000000000000000000000000000
status=0
while read local_ref local_sha remote_ref remote_sha; do
  [ "$local_sha" = "$z40" ] && continue
  if [ "$remote_sha" = "$z40" ]; then range="$local_sha"; else range="$remote_sha..$local_sha"; fi
  php scripts/check-repository-safety.php --history="$range" || status=1
done
exit $status
SH,
];

foreach ($hooks as $name => $body) {
    $path = $hooksDir . '/' . $name;
    if (is_file($path) && !str_contains((string) file_get_contents($path), 'Concursei:')) {
        fwrite(STDERR, "Hook $name já existe e não é do Concursei; não sobrescrito: $path\n");
        continue;
    }
    file_put_contents($path, str_replace("\r\n", "\n", $body) . "\n");
    @chmod($path, 0755);
    echo "Hook instalado: .git/hooks/$name\n";
}
