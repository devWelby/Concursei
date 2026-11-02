<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var string $kind orgaos|bancas|cargos
 * @var array{table:string,title:string,new:string} $cfg
 * @var array<string,mixed>|null $row
 */
use Concursei\Modules\Catalog\Vocabulary;

$view->share('noindex', true);
$r = $row ?? [];
$v = static fn (string $k): string => $h->old($k, $r[$k] ?? '');
$table = $cfg['table'];
?>
<?= $view->partial('admin/_nav', ['active' => $kind]) ?>
<p><a href="/admin/<?= e($kind) ?>">← <?= e($cfg['title']) ?></a></p>
<h1><?= $row === null ? e($cfg['new']) : 'Editar: ' . e($row['name']) ?></h1>
<?php if ($row !== null): ?>
  <p class="muted">Concursos já publicados continuam mostrando o nome antigo até serem publicados de novo.</p>
<?php endif; ?>

<form method="post" action="/admin/<?= e($kind) ?><?= $row !== null ? '/' . (int) $row['id'] : '' ?>" novalidate class="card">
  <?= $h->csrfField() ?>
  <?= $view->partial('partials/field', ['name' => 'name', 'label' => 'Nome', 'value' => $v('name'), 'attrs' => ['maxlength' => '200']]) ?>
  <?= $view->partial('partials/field', ['name' => 'slug', 'label' => 'Endereço (opcional)', 'value' => $v('slug'), 'required' => false, 'hint' => 'Usado nos filtros públicos. Vazio = gerado a partir do nome.', 'attrs' => ['maxlength' => '190', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*']]) ?>
  <?php if ($table !== 'careers'): ?>
    <?= $view->partial('partials/field', ['name' => 'acronym', 'label' => 'Sigla (opcional)', 'value' => $v('acronym'), 'required' => false, 'attrs' => ['maxlength' => '30']]) ?>
  <?php endif; ?>
  <?php if ($table === 'organizations'): ?>
    <div class="grid grid-2">
      <?= $view->partial('partials/select', ['name' => 'sphere', 'label' => 'Esfera', 'options' => Vocabulary::SPHERES, 'selected' => (string) ($r['sphere'] ?? ''), 'placeholder' => 'Selecione', 'required' => true]) ?>
      <?= $view->partial('partials/select', ['name' => 'uf', 'label' => 'UF', 'options' => Vocabulary::UFS, 'selected' => (string) ($r['uf'] ?? ''), 'placeholder' => 'Abrangência nacional']) ?>
    </div>
  <?php endif; ?>
  <?php if ($table !== 'careers'): ?>
    <?= $view->partial('partials/field', ['name' => 'website_url', 'label' => 'Site oficial (opcional)', 'type' => 'url', 'value' => $v('website_url'), 'required' => false, 'attrs' => ['maxlength' => '2048']]) ?>
  <?php else: ?>
    <div class="grid grid-2">
      <?= $view->partial('partials/select', ['name' => 'area', 'label' => 'Área', 'options' => Vocabulary::AREAS, 'selected' => (string) ($r['area'] ?? ''), 'placeholder' => 'Selecione', 'required' => true]) ?>
      <?= $view->partial('partials/select', ['name' => 'education_level', 'label' => 'Escolaridade', 'options' => Vocabulary::EDUCATION_LEVELS, 'selected' => (string) ($r['education_level'] ?? ''), 'placeholder' => 'Selecione', 'required' => true]) ?>
    </div>
    <?= $view->partial('partials/textarea', ['name' => 'description', 'label' => 'Descrição (opcional)', 'value' => $v('description'), 'maxlength' => 2000]) ?>
  <?php endif; ?>
  <button class="button" type="submit">Salvar</button>
</form>
