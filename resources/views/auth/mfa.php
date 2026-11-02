<?php /** @var \Concursei\Core\View\TemplateHelper $h @var \Concursei\Core\View\View $view */ ?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Verificação em duas etapas</h1>
  <p>Digite o código de 6 dígitos do seu aplicativo autenticador.</p>
  <form method="post" action="/entrar/verificacao" novalidate>
    <?= $h->csrfField() ?>
    <?= $view->partial('partials/field', ['name' => 'code', 'label' => 'Código', 'autocomplete' => 'one-time-code', 'value' => '',
        'hint' => 'Sem acesso ao aplicativo? Use um dos seus códigos de recuperação (formato xxxxx-xxxxx).',
        'attrs' => ['maxlength' => '20', 'autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
    <button class="button" type="submit">Verificar</button>
  </form>
  <p class="hint">A verificação expira em 5 minutos. Se demorar, <a href="/entrar">entre novamente</a>.</p>
</section>
