<?php /** Texto puro. @var string $name @var string $resetUrl @var string $appName */ ?>
Olá<?= $name !== '' ? ', ' . $name : '' ?>.

A senha da sua conta no <?= $appName ?> acabou de ser alterada e as outras sessões abertas foram encerradas.

Se não foi você, redefina a senha agora: <?= $resetUrl ?>
