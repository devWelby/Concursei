<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed> $contest
 * @var array<string,mixed>|null $event
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$view->share('noindex', true);
$base = '/admin/concursos/' . (int) $contest['id'];
$ev = $event ?? [];
$time = isset($ev['time_local']) && $ev['time_local'] !== null ? substr((string) $ev['time_local'], 0, 5) : '';
?>
<?= $view->partial('admin/_nav', ['active' => 'concursos']) ?>
<p><a href="<?= e($base) ?>">← <?= e($contest['title']) ?></a></p>
<h1><?= $event === null ? 'Nova data' : 'Editar data' ?></h1>

<form method="post" action="<?= e($base) ?>/datas<?= $event !== null ? '/' . (int) $event['id'] : '' ?>" novalidate class="card">
  <?= $h->csrfField() ?>
  <input type="hidden" name="version" value="<?= (int) $contest['version'] ?>">
  <?= $view->partial('partials/select', ['name' => 'type', 'label' => 'Evento', 'options' => Vocabulary::EVENT_TYPES, 'selected' => (string) ($ev['type'] ?? ''), 'placeholder' => 'Selecione', 'required' => true]) ?>
  <?= $view->partial('partials/field', ['name' => 'label', 'label' => 'Descrição (opcional)', 'value' => $h->old('label', $ev['label'] ?? ''), 'required' => false, 'hint' => 'Obrigatória para "Outro". Ex.: "Prova discursiva (Analista)".', 'attrs' => ['maxlength' => '200']]) ?>
  <div class="grid grid-2">
    <?= $view->partial('partials/field', ['name' => 'date', 'label' => 'Data', 'value' => $h->old('date', Format::eventDateInput($ev['date_value'] ?? null, (string) ($ev['date_precision'] ?? 'unknown'))), 'required' => false, 'hint' => 'DD/MM/AAAA se o dia foi divulgado; MM/AAAA se só o mês; AAAA se só o ano; vazio se ainda não há data. Nunca preencha um dia estimado.', 'attrs' => ['maxlength' => '10', 'inputmode' => 'numeric', 'placeholder' => 'DD/MM/AAAA']]) ?>
    <?= $view->partial('partials/field', ['name' => 'time', 'label' => 'Horário de Brasília (opcional)', 'type' => 'time', 'value' => $h->old('time', $time), 'required' => false, 'hint' => 'Somente com data exata.']) ?>
  </div>
  <?= $view->partial('partials/field', ['name' => 'note', 'label' => 'Observação (opcional)', 'value' => $h->old('note', $ev['note'] ?? ''), 'required' => false, 'attrs' => ['maxlength' => '300']]) ?>
  <button class="button" type="submit">Salvar data</button>
</form>
