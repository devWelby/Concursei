<?php /** @var \Concursei\Core\View\TemplateHelper $h @var \Concursei\Core\View\View $view @var bool $mailAvailable */ ?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Recuperar senha</h1>
  <?php if (!$mailAvailable): ?>
    <div class="alert alert-warning" role="status">A recuperação por e-mail está temporariamente indisponível porque o envio de e-mails ainda não foi configurado.</div>
  <?php else: ?>
    <p>Informe o e-mail da conta. Se ela existir, enviaremos um link válido por 1 hora.</p>
    <form method="post" action="/recuperar-senha" novalidate>
      <?= $h->csrfField() ?>
      <?= $view->partial('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'email', 'attrs' => ['maxlength' => '254']]) ?>
      <button class="button" type="submit">Enviar link</button>
    </form>
  <?php endif; ?>
  <p><a href="/entrar">Voltar para o login</a></p>
</section>
