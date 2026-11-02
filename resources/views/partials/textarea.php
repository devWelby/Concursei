<?php
/**
 * Área de texto acessível.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var string $name
 * @var string $label
 * Opcionais: $value, $hint, $required, $id, $maxlength, $rows
 */
$id ??= 'f-' . $name;
$hint ??= null;
$required ??= false;
$maxlength ??= null;
$rows ??= 4;
$error = $h->error($name);
$value ??= $h->old($name);
$describedBy = trim(($hint !== null ? $id . '-dica ' : '') . ($error !== null ? $id . '-erro' : ''));
?>
<div class="field">
  <label for="<?= e($id) ?>"><?= e($label) ?></label>
  <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= (int) $rows ?>"<?= $maxlength !== null ? ' maxlength="' . (int) $maxlength . '"' : '' ?><?= $required ? ' required' : '' ?><?= $error !== null ? ' aria-invalid="true"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '' ?>><?= e($value) ?></textarea>
  <?php if ($hint !== null): ?><span class="hint" id="<?= e($id) ?>-dica"><?= e($hint) ?></span><?php endif; ?>
  <?php if ($error !== null): ?><p class="field-error" id="<?= e($id) ?>-erro"><?= e($error) ?></p><?php endif; ?>
</div>
