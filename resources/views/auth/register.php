<?php
/** @var \Concursei\Core\View\TemplateHelper $h @var \Concursei\Core\View\View $view @var bool $mailAvailable */
$termsError = $h->error('accept_terms') ?? $h->error('age_18');
?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Criar conta</h1>
  <p>Grátis. Usamos seu e-mail para confirmar a conta e, se você permitir, para avisos de concursos.</p>
  <?php if (!$mailAvailable): ?>
    <div class="alert alert-warning" role="status">O cadastro está temporariamente indisponível porque o envio de e-mails de confirmação ainda não foi configurado.</div>
  <?php else: ?>
  <form method="post" action="/cadastro" novalidate>
    <?= $h->csrfField() ?>
    <?= $view->partial('partials/field', ['name' => 'display_name', 'label' => 'Como quer ser chamado(a)', 'autocomplete' => 'nickname', 'attrs' => ['maxlength' => '80']]) ?>
    <?= $view->partial('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'email', 'attrs' => ['maxlength' => '254']]) ?>
    <?= $view->partial('partials/field', ['name' => 'password', 'label' => 'Senha', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Mínimo de 10 caracteres. Frases longas são ótimas senhas.', 'attrs' => ['minlength' => '10', 'maxlength' => '256']]) ?>
    <?= $view->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'Repita a senha', 'type' => 'password', 'autocomplete' => 'new-password', 'attrs' => ['maxlength' => '256']]) ?>

    <fieldset class="field">
      <legend class="visually-hidden">Termos e consentimentos</legend>
      <label class="checkbox"><input type="checkbox" name="accept_terms" value="1" required<?= $termsError !== null ? ' aria-invalid="true" aria-describedby="erro-termos"' : '' ?>>
        <span>Li e aceito os <a href="/termos" target="_blank" rel="noopener">Termos de uso</a> e a <a href="/privacidade" target="_blank" rel="noopener">Política de privacidade</a>.</span></label>
      <label class="checkbox"><input type="checkbox" name="age_18" value="1" required>
        <span>Tenho 18 anos ou mais.</span></label>
      <label class="checkbox"><input type="checkbox" name="marketing" value="1">
        <span>Quero receber novidades do Concursei por e-mail (opcional; dá para cancelar quando quiser).</span></label>
      <?php if ($termsError !== null): ?><p class="field-error" id="erro-termos"><?= e($termsError) ?></p><?php endif; ?>
    </fieldset>

    <button class="button" type="submit">Criar conta</button>
  </form>
  <?php endif; ?>
  <p>Já tem conta? <a href="/entrar">Entrar</a></p>
</section>
