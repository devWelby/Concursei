<?php /** @var \Concursei\Core\View\TemplateHelper $h @var \Concursei\Core\View\View $view @var string $token @var bool $valid */ ?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Definir nova senha</h1>
  <?php if (!$valid): ?>
    <div class="alert alert-error" role="alert">Este link é inválido, já foi usado ou expirou.</div>
    <p><a class="button" href="/recuperar-senha">Pedir novo link</a></p>
  <?php else: ?>
    <form method="post" action="/redefinir-senha" novalidate>
      <?= $h->csrfField() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <?= $view->partial('partials/field', ['name' => 'password', 'label' => 'Nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Mínimo de 10 caracteres.', 'attrs' => ['minlength' => '10', 'maxlength' => '256']]) ?>
      <?= $view->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'Repita a nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'attrs' => ['maxlength' => '256']]) ?>
      <button class="button" type="submit">Salvar nova senha</button>
    </form>
    <p class="hint">Ao salvar, todas as sessões abertas da conta são encerradas.</p>
  <?php endif; ?>
</section>
