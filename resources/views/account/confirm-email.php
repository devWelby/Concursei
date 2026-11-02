<?php /** @var \Concursei\Core\View\TemplateHelper $h @var string $token */ ?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Confirmar novo e-mail</h1>
  <?php if ($token === ''): ?>
    <p>Link incompleto. Abra o link exatamente como recebido no e-mail.</p>
  <?php else: ?>
    <p>Clique no botão para confirmar que este passa a ser o e-mail da sua conta.</p>
    <form method="post" action="/conta/email/confirmar">
      <?= $h->csrfField() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <button class="button" type="submit">Confirmar novo e-mail</button>
    </form>
  <?php endif; ?>
</section>
