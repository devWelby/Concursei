<?php
/**
 * Seleção acessível (rótulo, dica e erro ligados por aria-describedby).
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var string $name
 * @var string $label
 * @var array<string|int,string> $options valor => texto
 * Opcionais: $selected (valor atual), $placeholder (opção vazia), $hint, $required, $id
 */
$id ??= 'f-' . $name;
$hint ??= null;
$required ??= false;
$placeholder ??= null;
$error = $h->error($name);
$current = $h->old($name, $selected ?? '');
$describedBy = trim(($hint !== null ? $id . '-dica ' : '') . ($error !== null ? $id . '-erro' : ''));
?>
<div class="field">
  <label for="<?= e($id) ?>"><?= e($label) ?></label>
  <select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?><?= $error !== null ? ' aria-invalid="true"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '' ?>>
    <?php if ($placeholder !== null): ?><option value=""><?= e($placeholder) ?></option><?php endif; ?>
    <?php foreach ($options as $value => $text): ?>
      <option value="<?= e((string) $value) ?>"<?= (string) $value === $current ? ' selected' : '' ?>><?= e($text) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($hint !== null): ?><span class="hint" id="<?= e($id) ?>-dica"><?= e($hint) ?></span><?php endif; ?>
  <?php if ($error !== null): ?><p class="field-error" id="<?= e($id) ?>-erro"><?= e($error) ?></p><?php endif; ?>
</div>
