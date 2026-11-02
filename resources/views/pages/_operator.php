<?php
/**
 * Identificação do responsável e canal de contato (do .env; nunca inventados).
 * @var string $operator
 * @var string $contact
 */
?>
<?php if ($operator === '' || $contact === ''): ?>
  <div class="alert alert-warning" role="status">
    Identificação do responsável pelo site pendente de configuração (OPERATOR_NAME e CONTACT_EMAIL).
  </div>
<?php endif; ?>
<dl class="facts card">
  <dt>Responsável</dt><dd><?= $operator !== '' ? e($operator) : 'a definir' ?></dd>
  <dt>Contato</dt><dd><?= $contact !== '' ? '<a href="mailto:' . e($contact) . '">' . e($contact) . '</a>' : 'a definir' ?></dd>
</dl>
