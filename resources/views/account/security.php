<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var \Concursei\Shared\Auth\AuthUser $user
 * Quando MFA desativado: string $secret, string $uri. Quando ativado: int $remainingCodes.
 */
?>
<section class="stack" aria-labelledby="titulo">
  <h1 id="titulo">Verificação em duas etapas</h1>
  <p><a href="/conta">← Voltar para a conta</a></p>

  <?php if ($user->mfaEnabled): ?>
    <div class="alert alert-success" role="status">A verificação em duas etapas está ativada.</div>
    <p>Códigos de recuperação restantes: <strong><?= (int) $remainingCodes ?></strong> de 10.
      <?php if ($remainingCodes <= 3): ?><span class="badge badge-warn">gere novos códigos</span><?php endif; ?></p>

    <div class="grid grid-2">
      <section class="card stack" aria-labelledby="novos-codigos">
        <h2 id="novos-codigos">Gerar novos códigos de recuperação</h2>
        <p class="hint">Os códigos antigos deixam de valer.</p>
        <form method="post" action="/conta/seguranca/codigos" novalidate>
          <?= $h->csrfField() ?>
          <?= $view->partial('partials/field', ['name' => 'current_password', 'id' => 'senha-codigos', 'label' => 'Senha atual', 'type' => 'password', 'autocomplete' => 'current-password', 'attrs' => ['maxlength' => '256']]) ?>
          <?= $view->partial('partials/field', ['name' => 'code', 'id' => 'codigo-codigos', 'label' => 'Código do aplicativo', 'autocomplete' => 'one-time-code', 'value' => '', 'attrs' => ['inputmode' => 'numeric', 'maxlength' => '20']]) ?>
          <button class="button" type="submit">Gerar novos códigos</button>
        </form>
      </section>

      <section class="card stack" aria-labelledby="desativar">
        <h2 id="desativar">Desativar</h2>
        <?php if ($user->isStaff()): ?>
          <p class="alert alert-warning">Contas da equipe perdem o acesso à administração sem a verificação em duas etapas.</p>
        <?php endif; ?>
        <form method="post" action="/conta/seguranca/desativar" novalidate data-confirm="Desativar a verificação em duas etapas deixa sua conta menos protegida. Continuar?">
          <?= $h->csrfField() ?>
          <?= $view->partial('partials/field', ['name' => 'current_password', 'id' => 'senha-desativar', 'label' => 'Senha atual', 'type' => 'password', 'autocomplete' => 'current-password', 'attrs' => ['maxlength' => '256']]) ?>
          <?= $view->partial('partials/field', ['name' => 'code', 'id' => 'codigo-desativar', 'label' => 'Código do aplicativo ou de recuperação', 'autocomplete' => 'one-time-code', 'value' => '', 'attrs' => ['maxlength' => '20']]) ?>
          <button class="button button-danger" type="submit">Desativar</button>
        </form>
      </section>
    </div>
  <?php else: ?>
    <p>Com a verificação em duas etapas, além da senha é preciso um código temporário gerado no seu celular. <?= $user->isStaff() ? '<strong>Obrigatória para a equipe.</strong>' : 'Recomendada.' ?></p>
    <ol class="stack">
      <li>Instale um aplicativo autenticador compatível com TOTP (por exemplo, os de código aberto ou os dos grandes provedores).</li>
      <li>No aplicativo, adicione uma conta pela opção de <strong>chave de configuração</strong> e digite a chave abaixo (tipo: baseada em tempo).
        <p><code class="secret-key" aria-label="Chave de configuração"><?= e($secret) ?></code></p>
        <p class="hint">No celular, você também pode tocar em <a href="<?= e($uri) ?>">abrir no aplicativo autenticador</a>. Não compartilhe esta chave.</p>
      </li>
      <li>Digite abaixo o código de 6 dígitos que o aplicativo mostrar, junto com a sua senha.</li>
    </ol>
    <form class="form-narrow" method="post" action="/conta/seguranca/ativar" novalidate>
      <?= $h->csrfField() ?>
      <?= $view->partial('partials/field', ['name' => 'current_password', 'label' => 'Senha atual', 'type' => 'password', 'autocomplete' => 'current-password', 'attrs' => ['maxlength' => '256']]) ?>
      <?= $view->partial('partials/field', ['name' => 'code', 'label' => 'Código de 6 dígitos', 'autocomplete' => 'one-time-code', 'value' => '', 'attrs' => ['inputmode' => 'numeric', 'maxlength' => '20']]) ?>
      <button class="button" type="submit">Ativar</button>
    </form>
  <?php endif; ?>
</section>
