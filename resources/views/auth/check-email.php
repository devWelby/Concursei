<?php /** @var \Concursei\Core\View\TemplateHelper $h @var \Concursei\Core\View\View $view */ ?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Confirme seu e-mail</h1>
  <p>Se o endereço puder ser cadastrado, enviamos um link de confirmação. Abra-o em até 24 horas para ativar a conta.</p>
  <p class="hint">Não chegou? Confira a caixa de spam ou promoções. Você pode pedir um novo link abaixo (há um limite de envios por hora).</p>
  <form method="post" action="/cadastro/reenviar" novalidate>
    <?= $h->csrfField() ?>
    <?= $view->partial('partials/field', ['name' => 'email', 'label' => 'E-mail do cadastro', 'type' => 'email', 'autocomplete' => 'email', 'attrs' => ['maxlength' => '254']]) ?>
    <button class="button button-secondary" type="submit">Enviar novo link</button>
  </form>
</section>
