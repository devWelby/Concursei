<?php /** Texto puro. @var string $name @var string $url @var int $minutes @var string $appName */ ?>
Olá<?= $name !== '' ? ', ' . $name : '' ?>.

Recebemos um pedido para redefinir a senha da sua conta no <?= $appName ?>. Use o link abaixo (vale por <?= (int) $minutes ?> minutos e só funciona uma vez):

<?= $url ?>


Se você não pediu, ignore este e-mail: sua senha atual continua valendo.
