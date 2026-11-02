<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed> $contest
 * @var array<string,mixed>|null $offer
 * @var array<int,string> $careers
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$view->share('noindex', true);
$base = '/admin/concursos/' . (int) $contest['id'];
$o = $offer ?? [];
$v = static fn (string $k): string => $h->old($k, $o[$k] ?? '');
$reserve = $h->hasErrors() ? $h->old('reserve') !== '' : (bool) ($o['reserve'] ?? false);
?>
<?= $view->partial('admin/_nav', ['active' => 'concursos']) ?>
<p><a href="<?= e($base) ?>">← <?= e($contest['title']) ?></a></p>
<h1><?= $offer === null ? 'Novo cargo' : 'Editar cargo' ?></h1>

<form method="post" action="<?= e($base) ?>/cargos<?= $offer !== null ? '/' . (int) $offer['id'] : '' ?>" novalidate class="card">
  <?= $h->csrfField() ?>
  <input type="hidden" name="version" value="<?= (int) $contest['version'] ?>">
  <?= $view->partial('partials/field', ['name' => 'title', 'label' => 'Nome do cargo (como no edital)', 'value' => $v('title'), 'attrs' => ['maxlength' => '200']]) ?>
  <?= $view->partial('partials/select', ['name' => 'career_id', 'label' => 'Carreira', 'options' => $careers, 'selected' => (string) ($o['career_id'] ?? ''), 'placeholder' => 'Sem vínculo', 'hint' => 'Usada nos filtros por área. Cadastre em Administração → Cargos.']) ?>
  <?= $view->partial('partials/select', ['name' => 'education_level', 'label' => 'Escolaridade exigida', 'options' => Vocabulary::EDUCATION_LEVELS, 'selected' => (string) ($o['education_level'] ?? ''), 'placeholder' => 'Selecione', 'required' => true]) ?>
  <div class="grid grid-2">
    <?= $view->partial('partials/field', ['name' => 'vacancies', 'label' => 'Vagas imediatas', 'type' => 'number', 'value' => $v('vacancies'), 'required' => false, 'hint' => 'Vazio = o edital não informa. 0 = somente cadastro de reserva.', 'attrs' => ['min' => '0', 'max' => '100000', 'inputmode' => 'numeric']]) ?>
    <div class="field">
      <span class="label-like">Cadastro de reserva</span>
      <label class="checkbox"><input type="checkbox" name="reserve" value="1"<?= $reserve ? ' checked' : '' ?>> Há formação de cadastro de reserva</label>
    </div>
  </div>
  <div class="grid grid-2">
    <?= $view->partial('partials/field', ['name' => 'salary', 'label' => 'Remuneração inicial (R$)', 'value' => $h->old('salary', Format::moneyInput($o['salary_cents'] ?? null)), 'required' => false, 'hint' => 'Ex.: 4.500,00. Vazio = não informada.', 'attrs' => ['inputmode' => 'decimal', 'maxlength' => '20']]) ?>
    <?= $view->partial('partials/field', ['name' => 'workload_hours', 'label' => 'Carga horária semanal (h)', 'type' => 'number', 'value' => $v('workload_hours'), 'required' => false, 'attrs' => ['min' => '1', 'max' => '60', 'inputmode' => 'numeric']]) ?>
  </div>
  <?= $view->partial('partials/field', ['name' => 'salary_note', 'label' => 'Composição da remuneração', 'value' => $v('salary_note'), 'required' => false, 'hint' => 'Ex.: vencimento + gratificação; auxílios.', 'attrs' => ['maxlength' => '500']]) ?>
  <?= $view->partial('partials/field', ['name' => 'location', 'label' => 'Lotação', 'value' => $v('location'), 'required' => false, 'attrs' => ['maxlength' => '200']]) ?>
  <?= $view->partial('partials/textarea', ['name' => 'requirements', 'label' => 'Requisitos', 'value' => $v('requirements'), 'maxlength' => 3000]) ?>
  <?= $view->partial('partials/field', ['name' => 'sort_order', 'label' => 'Ordem de exibição', 'type' => 'number', 'value' => $v('sort_order'), 'required' => false, 'attrs' => ['min' => '0', 'max' => '999']]) ?>
  <button class="button" type="submit">Salvar cargo</button>
</form>
