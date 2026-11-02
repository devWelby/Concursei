<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var \Concursei\Shared\Auth\AuthUser $user
 * @var list<string> $timezones
 * @var array<string,array{granted:bool,version:string,at:string}> $consents
 */
$view->share('noindex', true);
$tzName = static fn (string $tz): string => str_replace(['America/', '_'], ['', ' '], $tz);
$localTime = static function (string $utc) use ($user): string {
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($user->timezone))->format('d/m/Y H:i');
    } catch (Exception) {
        return '';
    }
};
$currentTz = $h->old('timezone', $user->timezone);
$marketing = $consents['marketing_email']['granted'] ?? false;
?>
<h1>Minha conta</h1>

<div class="grid grid-2">
  <section class="card stack" aria-labelledby="perfil">
    <h2 id="perfil">Perfil</h2>
    <p>E-mail: <strong><?= e($user->email) ?></strong></p>
    <form method="post" action="/conta/perfil" novalidate>
      <?= $h->csrfField() ?>
      <?= $view->partial('partials/field', ['name' => 'display_name', 'label' => 'Nome de exibição', 'autocomplete' => 'nickname', 'value' => $h->old('display_name', $user->displayName), 'attrs' => ['maxlength' => '80']]) ?>
      <div class="field">
        <label for="f-timezone">Fuso horário</label>
        <select id="f-timezone" name="timezone" aria-describedby="f-timezone-dica">
          <?php foreach ($timezones as $tz): ?>
            <option value="<?= e($tz) ?>"<?= $tz === $currentTz ? ' selected' : '' ?>><?= e($tzName($tz)) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint" id="f-timezone-dica">Usado para exibir prazos e o seu cronograma de estudos.</span>
        <?php if (($err = $h->error('timezone')) !== null): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
      </div>
      <button class="button" type="submit">Salvar perfil</button>
    </form>
  </section>

  <section class="card stack" aria-labelledby="seguranca">
    <h2 id="seguranca">Segurança</h2>
    <p>Verificação em duas etapas:
      <?php if ($user->mfaEnabled): ?><span class="badge badge-success">ativada</span><?php else: ?><span class="badge badge-warn">desativada</span><?php endif; ?>
    </p>
    <p><a class="button button-secondary" href="/conta/seguranca">Gerenciar verificação em duas etapas</a></p>

    <h3>Trocar senha</h3>
    <form method="post" action="/conta/senha" novalidate>
      <?= $h->csrfField() ?>
      <?= $view->partial('partials/field', ['name' => 'current_password', 'id' => 'senha-atual-1', 'label' => 'Senha atual', 'type' => 'password', 'autocomplete' => 'current-password', 'attrs' => ['maxlength' => '256']]) ?>
      <?= $view->partial('partials/field', ['name' => 'password', 'label' => 'Nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Mínimo de 10 caracteres. As outras sessões abertas serão encerradas.', 'attrs' => ['minlength' => '10', 'maxlength' => '256']]) ?>
      <?= $view->partial('partials/field', ['name' => 'password_confirmation', 'label' => 'Repita a nova senha', 'type' => 'password', 'autocomplete' => 'new-password', 'attrs' => ['maxlength' => '256']]) ?>
      <button class="button" type="submit">Trocar senha</button>
    </form>
  </section>

  <section class="card stack" aria-labelledby="email">
    <h2 id="email">Trocar e-mail</h2>
    <p class="hint">Enviaremos um link de confirmação para o novo endereço. A troca só vale depois da confirmação, e o endereço atual será avisado.</p>
    <form method="post" action="/conta/email" novalidate>
      <?= $h->csrfField() ?>
      <?= $view->partial('partials/field', ['name' => 'new_email', 'label' => 'Novo e-mail', 'type' => 'email', 'autocomplete' => 'email', 'attrs' => ['maxlength' => '254']]) ?>
      <?= $view->partial('partials/field', ['name' => 'current_password', 'id' => 'senha-atual-2', 'label' => 'Senha atual', 'type' => 'password', 'autocomplete' => 'current-password', 'attrs' => ['maxlength' => '256']]) ?>
      <button class="button" type="submit">Enviar confirmação</button>
    </form>
  </section>

  <section class="card stack" aria-labelledby="privacidade">
    <h2 id="privacidade">Privacidade e comunicações</h2>
    <form method="post" action="/conta/preferencias">
      <?= $h->csrfField() ?>
      <label class="checkbox"><input type="checkbox" name="marketing" value="1"<?= $marketing ? ' checked' : '' ?>>
        <span>Receber novidades do Concursei por e-mail.</span></label>
      <button class="button button-secondary" type="submit">Salvar preferências</button>
    </form>
    <?php if ($consents !== []): ?>
      <details>
        <summary>Histórico de aceites</summary>
        <ul>
          <?php foreach ($consents as $purpose => $c): ?>
            <li><?= e($purpose) ?>: <?= $c['granted'] ? 'aceito' : 'recusado' ?> (versão <?= e($c['version']) ?>, <?= e($localTime($c['at'])) ?>)</li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
    <p><a href="/conta/dados">Baixar meus dados ou excluir a conta</a> · <a href="/privacidade">Política de privacidade</a></p>
  </section>
</div>
