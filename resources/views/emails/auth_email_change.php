<?php /** Texto puro. @var string $name @var string $url @var string $appName */ ?>
Olá<?= $name !== '' ? ', ' . $name : '' ?>.

Foi pedido que este endereço passe a ser o e-mail de uma conta no <?= $appName ?>. Para confirmar, abra o link abaixo (vale por 24 horas):

<?= $url ?>


Se não foi você, ignore esta mensagem: nada muda sem a confirmação.
