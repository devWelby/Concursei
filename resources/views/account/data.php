<?php
/**
 * Meus dados: cópia (portabilidade/acesso) e exclusão da conta.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var \Concursei\Shared\Auth\AuthUser $user
 */
$view->share('noindex', true);
$staff = $user->role !== 'student';
?>
<p><a href="/conta">← Minha conta</a></p>
<h1>Meus dados</h1>
<p>Você tem direito de acessar, levar para outro serviço e excluir os seus dados pessoais (LGPD, art. 18). Veja o que tratamos na <a href="/privacidade">Política de privacidade</a>.</p>

<div class="grid grid-2">
  <section class="card stack" aria-labelledby="copia">
    <h2 id="copia">Baixar uma cópia</h2>
    <p>Arquivo JSON com seus dados de cadastro, aceites, concursos acompanhados, sessões ativas, e-mails enviados (sem o conteúdo) e registro de segurança.</p>
    <form method="post" action="/conta/dados/exportar" novalidate>
      <?= $h->csrfField() ?>
      <?= $view->partial('partials/field', ['name' => 'password', 'label' => 'Confirme sua senha', 'type' => 'password', 'autocomplete' => 'current-password', 'value' => '']) ?>
      <button class="button" type="submit">Baixar meus dados</button>
    </form>
  </section>

  <section class="card stack" aria-labelledby="excluir">
    <h2 id="excluir">Excluir minha conta</h2>
    <?php if ($staff): ?>
      <p>Contas da equipe não são excluídas por aqui. Um administrador deve primeiro mudar o papel da conta para estudante.</p>
    <?php else: ?>
      <p>A exclusão é <strong>definitiva e imediata</strong>:</p>
      <ul>
        <li>seu e-mail, nome e senha são apagados e você sai de todos os aparelhos;</li>
        <li>concursos acompanhados, links pendentes e códigos de recuperação são apagados;</li>
        <li>registros de segurança e de aceites ficam guardados sem identificar você, como explicado na política de privacidade.</li>
      </ul>
      <p>Se quiser uma cópia, baixe seus dados antes. Depois, você pode criar uma conta nova com o mesmo e-mail.</p>
      <form method="post" action="/conta/excluir" novalidate>
        <?= $h->csrfField() ?>
        <?= $view->partial('partials/field', ['name' => 'delete_password', 'label' => 'Confirme sua senha', 'type' => 'password', 'autocomplete' => 'current-password', 'value' => '']) ?>
        <div class="field">
          <label class="checkbox"><input type="checkbox" name="confirm" value="1"<?= $h->error('confirm') !== null ? ' aria-invalid="true" aria-describedby="f-confirm-erro"' : '' ?>>
            <span>Entendo que a exclusão é definitiva e não pode ser desfeita.</span></label>
          <?php if ($h->error('confirm') !== null): ?><p class="field-error" id="f-confirm-erro"><?= e($h->error('confirm')) ?></p><?php endif; ?>
        </div>
        <button class="button button-danger" type="submit">Excluir minha conta</button>
      </form>
    <?php endif; ?>
  </section>
</div>
