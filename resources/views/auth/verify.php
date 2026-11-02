<?php /** @var \Concursei\Core\View\TemplateHelper $h @var string $token */ ?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Confirmar e-mail</h1>
  <?php if ($token === ''): ?>
    <p>Link incompleto. Abra o link exatamente como recebido no e-mail ou <a href="/cadastro/verifique-email">peça um novo</a>.</p>
  <?php else: ?>
    <p>Clique no botão para confirmar seu e-mail e ativar a conta.</p>
    <form method="post" action="/confirmar-email">
      <?= $h->csrfField() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <button class="button" type="submit">Confirmar meu e-mail</button>
    </form>
  <?php endif; ?>
</section>
