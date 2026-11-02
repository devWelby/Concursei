<?php /** Texto puro. @var string $name @var bool $enabled @var string $appName */ ?>
Olá<?= $name !== '' ? ', ' . $name : '' ?>.

<?= $enabled
    ? 'A verificação em duas etapas foi ATIVADA na sua conta do ' . $appName . '. Guarde os códigos de recuperação em local seguro.'
    : 'A verificação em duas etapas foi DESATIVADA na sua conta do ' . $appName . '.' ?>


Se não foi você, troque sua senha imediatamente e fale com o suporte.
