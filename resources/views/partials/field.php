<?php
/**
 * Campo de formulário acessível.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var string $name
 * @var string $label
 * Opcionais: $type, $hint, $autocomplete, $required, $value, $id, $attrs (array<string,string>)
 */
$type ??= 'text';
$id ??= 'f-' . $name;
$hint ??= null;
$required ??= true;
$attrs ??= [];
$error = $h->error($name);
// Senhas nunca são repovoadas.
$value ??= $type === 'password' ? '' : $h->old($name);
$describedBy = trim(($hint !== null ? $id . '-dica ' : '') . ($error !== null ? $id . '-erro' : ''));
?>
<div class="field">
  <label for="<?= e($id) ?>"><?= e($label) ?></label>
  <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>"<?php if ($type !== 'password'): ?> value="<?= e($value) ?>"<?php endif; ?>
    <?php if (!empty($autocomplete)): ?>autocomplete="<?= e($autocomplete) ?>"<?php endif; ?>
    <?= $required ? 'required' : '' ?>
    <?php foreach ($attrs as $k => $v): ?><?= preg_match('/^[a-z-]+$/', (string) $k) ? e((string) $k) . '="' . e((string) $v) . '"' : '' ?> <?php endforeach; ?>
    <?php if ($error !== null): ?>aria-invalid="true"<?php endif; ?>
    <?php if ($describedBy !== ''): ?>aria-describedby="<?= e($describedBy) ?>"<?php endif; ?>>
  <?php if ($hint !== null): ?><span class="hint" id="<?= e($id) ?>-dica"><?= e($hint) ?></span><?php endif; ?>
  <?php if ($error !== null): ?><p class="field-error" id="<?= e($id) ?>-erro"><?= e($error) ?></p><?php endif; ?>
</div>
