<?php
/**
 * Campos principais do concurso (criação e edição).
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed>|null $contest
 * @var array<int,string> $organizations
 * @var array<int,string> $boards
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$c = $contest ?? [];
$v = static fn (string $k): string => $h->old($k, $c[$k] ?? '');
$slugLocked = ($c['published_revision_id'] ?? null) !== null;
?>
<fieldset>
  <legend class="visually-hidden">Identificação</legend>
  <?= $view->partial('partials/field', ['name' => 'title', 'label' => 'Título', 'value' => $v('title'), 'hint' => 'Como o concurso é conhecido, com o ano. Ex.: "Tribunal X 2027 — Analista e Técnico".', 'attrs' => ['maxlength' => '200']]) ?>
  <?php if ($slugLocked): ?>
    <p>Endereço público: <code>/concursos/<?= e($c['slug']) ?></code> <span class="muted">(fixo depois da primeira publicação)</span></p>
  <?php else: ?>
    <?= $view->partial('partials/field', ['name' => 'slug', 'label' => 'Endereço (opcional)', 'value' => $v('slug'), 'required' => false, 'hint' => 'Letras minúsculas, números e hífens. Vazio = gerado a partir do título. Não muda depois de publicado.', 'attrs' => ['maxlength' => '190', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*']]) ?>
  <?php endif; ?>
  <?= $view->partial('partials/select', ['name' => 'organization_id', 'label' => 'Órgão', 'options' => $organizations, 'selected' => (string) ($c['organization_id'] ?? ''), 'placeholder' => 'Selecione', 'required' => true, 'hint' => $organizations === [] ? 'Cadastre o órgão primeiro em Administração → Órgãos.' : null]) ?>
  <?= $view->partial('partials/select', ['name' => 'exam_board_id', 'label' => 'Banca', 'options' => $boards, 'selected' => (string) ($c['exam_board_id'] ?? ''), 'placeholder' => 'Ainda não definida']) ?>
  <div class="grid grid-2">
    <?= $view->partial('partials/field', ['name' => 'edition_year', 'label' => 'Ano da edição', 'type' => 'number', 'value' => $v('edition_year'), 'required' => false, 'attrs' => ['min' => '2000', 'max' => '2100', 'inputmode' => 'numeric']]) ?>
    <?= $view->partial('partials/select', ['name' => 'uf', 'label' => 'UF', 'options' => Vocabulary::UFS, 'selected' => (string) ($c['uf'] ?? ''), 'placeholder' => 'Nacional ou várias UFs']) ?>
  </div>
  <?= $view->partial('partials/select', ['name' => 'status', 'label' => 'Situação informada', 'options' => Vocabulary::STATUSES, 'selected' => (string) ($c['status'] ?? 'predicted'), 'required' => true, 'hint' => 'A fase exibida ao público (inscrições abertas, prova realizada...) é calculada pelas datas exatas cadastradas.']) ?>
  <?= $view->partial('partials/textarea', ['name' => 'summary', 'label' => 'Resumo (opcional)', 'value' => $v('summary'), 'maxlength' => 5000, 'hint' => 'Texto simples, sem HTML. Não copie trechos longos do edital.']) ?>
</fieldset>

<fieldset>
  <legend class="visually-hidden">Taxa e links</legend>
  <div class="grid grid-2">
    <?= $view->partial('partials/field', ['name' => 'fee', 'label' => 'Taxa de inscrição (R$)', 'value' => $h->old('fee', Format::moneyInput($c['fee_cents'] ?? null)), 'required' => false, 'hint' => 'Ex.: 120,00. Vazio = não informada.', 'attrs' => ['inputmode' => 'decimal', 'maxlength' => '20']]) ?>
    <?= $view->partial('partials/field', ['name' => 'fee_note', 'label' => 'Observação sobre a taxa', 'value' => $v('fee_note'), 'required' => false, 'hint' => 'Ex.: valores por cargo, regras de isenção.', 'attrs' => ['maxlength' => '500']]) ?>
  </div>
  <?= $view->partial('partials/field', ['name' => 'official_url', 'label' => 'Página oficial do concurso', 'type' => 'url', 'value' => $v('official_url'), 'required' => false, 'attrs' => ['maxlength' => '2048']]) ?>
  <?= $view->partial('partials/field', ['name' => 'registration_url', 'label' => 'Página de inscrição', 'type' => 'url', 'value' => $v('registration_url'), 'required' => false, 'attrs' => ['maxlength' => '2048']]) ?>
  <div class="grid grid-2">
    <?= $view->partial('partials/field', ['name' => 'source_url', 'label' => 'Documento oficial usado na conferência (fonte)', 'type' => 'url', 'value' => $v('source_url'), 'required' => false, 'hint' => 'Obrigatório para publicar: edital, extrato ou ato oficial (https).', 'attrs' => ['maxlength' => '2048']]) ?>
    <?= $view->partial('partials/field', ['name' => 'source_published_on', 'label' => 'Data do documento', 'type' => 'date', 'value' => $v('source_published_on'), 'required' => false]) ?>
  </div>
</fieldset>
