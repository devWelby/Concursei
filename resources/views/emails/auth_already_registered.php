<?php /** Texto puro. @var string $name @var string $loginUrl @var string $resetUrl @var string $appName */ ?>
Olá<?= $name !== '' ? ', ' . $name : '' ?>.

Alguém tentou criar uma conta no <?= $appName ?> usando este e-mail, mas você já tem cadastro. Nenhuma alteração foi feita.

Para entrar: <?= $loginUrl ?>

Esqueceu a senha? <?= $resetUrl ?>


Se não foi você, nenhuma ação é necessária.
