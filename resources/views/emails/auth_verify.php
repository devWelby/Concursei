<?php /** Texto puro. @var string $name @var string $url @var string $appName */ ?>
Olá<?= $name !== '' ? ', ' . $name : '' ?>.

Recebemos um cadastro no <?= $appName ?> com este e-mail. Para ativar a conta, abra o link abaixo e confirme (vale por 24 horas):

<?= $url ?>


Se não foi você, ignore esta mensagem: sem a confirmação, a conta não é ativada.
