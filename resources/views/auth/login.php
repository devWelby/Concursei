<?php /** @var \Concursei\Core\View\TemplateHelper $h @var \Concursei\Core\View\View $view */ ?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Entrar</h1>
  <form method="post" action="/entrar" novalidate>
    <?= $h->csrfField() ?>
    <?= $view->partial('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'username', 'attrs' => ['maxlength' => '254']]) ?>
    <?= $view->partial('partials/field', ['name' => 'password', 'label' => 'Senha', 'type' => 'password', 'autocomplete' => 'current-password', 'attrs' => ['maxlength' => '256']]) ?>
    <button class="button" type="submit">Entrar</button>
  </form>
  <p><a href="/recuperar-senha">Esqueci minha senha</a></p>
  <p>Ainda não tem conta? <a href="/cadastro">Criar conta</a></p>
</section>
