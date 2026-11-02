<?php
/**
 * Revisão humana de uma proposta da IA. Todo texto da proposta veio do modelo/documento: exibido escapado.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed> $x
 * @var ?array<string,mixed> $contest
 * @var array<string,array{status:string,target:?int,current:?string,changes:list<string>}> $diff
 */
use Concursei\Modules\Catalog\Format;

$view->share('noindex', true);
$labels = require __DIR__ . '/_labels.php';
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
[$statusLabel, $statusClass] = $labels['status'][$x['status']];
$proposal = is_array($x['proposal']) ? $x['proposal'] : null;
$official = $x['source_kind'] === 'official';
$canApply = $x['status'] === 'ready' && $official && $contest !== null && $contest['archived_at'] === null;
$sections = ['fee' => 'Taxa de inscrição', 'event' => 'Datas', 'offer' => 'Cargos'];
$byKind = ['fee' => [], 'event' => [], 'offer' => []];
foreach ($proposal['items'] ?? [] as $item) {
    $byKind[$item['kind']][] = $item;
}
$fieldNames = ['education_level' => 'escolaridade', 'vacancies' => 'vagas', 'reserve' => 'cadastro de reserva', 'salary_cents' => 'remuneração', 'workload_hours' => 'carga horária', 'requirements' => 'requisitos', 'date_value' => 'data'];
?>
<?= $view->partial('admin/_nav', ['active' => 'extracoes']) ?>
<p><a href="/admin/extracoes">← Propostas da IA</a></p>
<h1>Proposta da IA #<?= (int) $x['id'] ?></h1>

<dl class="facts card">
  <dt>Situação</dt><dd><span class="<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></dd>
  <dt>Documento</dt><dd><a href="/admin/documentos/<?= (int) $x['document_id'] ?>"><?= e($x['document_title'] ?? $x['document_url']) ?></a></dd>
  <dt>Fonte</dt><dd><?= e($x['source_name']) ?> (<?= $official ? 'oficial' : 'secundária' ?>)</dd>
  <dt>Concurso</dt><dd><?= $contest !== null ? '<a href="/admin/concursos/' . (int) $contest['id'] . '">' . e($contest['title']) . '</a>' : 'Concurso removido' ?></dd>
  <dt>Pedida</dt><dd><?= e(Format::localDate($x['created_at'], $tz, true)) ?><?= $x['requested_by_name'] !== null ? ' por ' . e($x['requested_by_name']) : '' ?></dd>
  <dt>Modelo</dt><dd><?= e($x['model'] ?? '—') ?> · prompt <?= e($x['prompt_version']) ?><?= $x['reused_from'] !== null ? ' · reaproveitada da proposta #' . (int) $x['reused_from'] . ' (mesmo texto)' : '' ?></dd>
  <?php if ($x['reviewed_at'] !== null): ?>
    <dt>Revisão</dt><dd><?= e(Format::localDate($x['reviewed_at'], $tz, true)) ?><?= $x['reviewed_by_name'] !== null ? ' por ' . e($x['reviewed_by_name']) : '' ?><?= $x['review_note'] !== null ? ' — ' . e($x['review_note']) : '' ?></dd>
  <?php endif; ?>
</dl>

<?php if (in_array($x['status'], ['queued', 'running'], true)): ?>
  <div class="alert alert-info" role="status">
    A extração roda pela fila na próxima execução do Cron. <a href="/admin/extracoes/<?= (int) $x['id'] ?>">Atualizar</a>
    <?= $x['error'] !== null ? '<br>' . e($x['error']) : '' ?>
  </div>
<?php elseif ($x['status'] === 'failed'): ?>
  <div class="alert alert-error" role="alert">
    <?= e($x['error'] ?? 'A extração falhou.') ?>
    <a href="/admin/documentos/<?= (int) $x['document_id'] ?>/extrair">Pedir novamente</a> ou cadastre os dados manualmente.
  </div>
<?php endif; ?>

<?php if ($proposal !== null): ?>
  <div class="alert alert-info">
    A IA apenas propõe. Um item só é aceito quando o trecho citado existe no documento e contém o valor;
    mesmo assim, confira cada item com o documento original. Aplicar leva os itens ao <strong>rascunho</strong>:
    nada é publicado e o concurso não é marcado como conferido.
  </div>
  <?php if (!$official): ?>
    <div class="alert alert-warning">Fonte secundária: esta proposta é só para consulta e não pode ser aplicada. Localize o documento oficial.</div>
  <?php endif; ?>
  <?php if ($proposal['partial']): ?>
    <div class="alert alert-warning">Documento extenso: só parte das páginas foi enviada à IA. Informações ausentes aqui podem estar no restante do documento.</div>
  <?php endif; ?>
  <?php foreach ($proposal['notes'] as $note): ?>
    <div class="alert alert-warning"><?= e($note) ?></div>
  <?php endforeach; ?>

  <section class="card" aria-labelledby="lido">
    <h2 id="lido">Identificação lida no documento</h2>
    <p class="meta">Informativo: estes campos não são aplicados ao concurso.</p>
    <dl class="facts">
      <dt>Tipo</dt><dd><?= e($labels['document_kind'][$proposal['info']['document_kind']] ?? 'Outro documento') ?></dd>
      <dt>Título</dt><dd><?= e($proposal['info']['contest_title'] ?? '—') ?></dd>
      <dt>Órgão</dt><dd><?= e($proposal['info']['organization_name'] ?? '—') ?></dd>
      <dt>Banca</dt><dd><?= e($proposal['info']['exam_board_name'] ?? '—') ?></dd>
    </dl>
    <?php if ($proposal['missing'] !== []): ?>
      <p><strong>Não encontrado no documento:</strong></p>
      <ul><?php foreach ($proposal['missing'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>

  <?php if ($canApply): ?>
  <form method="post" action="/admin/extracoes/<?= (int) $x['id'] ?>/aplicar">
    <?= $h->csrfField() ?>
    <input type="hidden" name="version" value="<?= (int) $contest['version'] ?>">
  <?php endif; ?>

  <?php foreach ($sections as $kind => $heading): ?>
    <section aria-labelledby="sec-<?= e($kind) ?>">
      <h2 id="sec-<?= e($kind) ?>"><?= e($heading) ?></h2>
      <?php if ($byKind[$kind] === []): ?>
        <p>Nenhum item proposto.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="proposal">
            <thead><tr><th scope="col">Aplicar</th><th scope="col">Proposta</th><th scope="col">No rascunho</th><th scope="col">Trecho do documento</th></tr></thead>
            <tbody>
              <?php foreach ($byKind[$kind] as $item): ?>
                <?php
                $d = $diff[$item['key']] ?? ['status' => $item['valid'] ? null : 'blocked', 'target' => null, 'current' => null, 'changes' => []];
                $selectable = $canApply && in_array($d['status'], ['new', 'changed'], true);
                $inputId = 'item-' . $item['key'];
                ?>
                <tr>
                  <td>
                    <?php if ($selectable): ?>
                      <input type="checkbox" id="<?= e($inputId) ?>" name="items[]" value="<?= e($item['key']) ?>" checked>
                      <label for="<?= e($inputId) ?>" class="visually-hidden">Aplicar: <?= e($item['shown']) ?></label>
                    <?php else: ?>
                      <span aria-hidden="true">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= e($item['shown']) ?></td>
                  <td>
                    <?php if ($d['status'] !== null): ?>
                      <?php [$diffLabel, $diffClass] = $labels['diff'][$d['status']]; ?>
                      <span class="<?= e($diffClass) ?>"><?= e($diffLabel) ?></span>
                      <?php if ($d['status'] === 'changed' && $d['current'] !== null): ?><br><span class="meta">Hoje: <?= e($d['current']) ?></span><?php endif; ?>
                      <?php if ($d['status'] === 'changed' && $kind === 'offer' && $d['changes'] !== []): ?>
                        <br><span class="meta">Muda: <?= e(implode(', ', array_map(static fn (string $f): string => $fieldNames[$f] ?? $f, $d['changes']))) ?></span>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($item['issues'] !== []): ?>
                      <ul class="issues"><?php foreach ($item['issues'] as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($item['evidence'] !== null): ?>
                      <blockquote class="evidence">“<?= e($item['evidence']) ?>”</blockquote>
                    <?php endif; ?>
                    <?php if ($item['page'] !== null): ?><span class="meta">página <?= (int) $item['page'] ?></span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($canApply): ?>
    <div class="card stack">
      <label class="checkbox"><input type="checkbox" name="use_as_source" value="1"> Registrar este documento como fonte oficial do concurso (endereço usado na conferência)</label>
      <p class="meta">Itens marcados "Igual ao rascunho" ou "Bloqueado" nunca são aplicados. Se mais de uma taxa for aplicada, o rascunho guarda a menor e descreve as demais na observação da taxa.</p>
      <p><button class="button" type="submit">Aplicar selecionados ao rascunho</button></p>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($x['status'] === 'ready'): ?>
    <form method="post" action="/admin/extracoes/<?= (int) $x['id'] ?>/descartar" class="card" novalidate>
      <?= $h->csrfField() ?>
      <?= $view->partial('partials/textarea', ['name' => 'review_note', 'label' => 'Motivo do descarte (opcional)', 'value' => '', 'maxlength' => 500, 'rows' => 2]) ?>
      <button class="button button-secondary" type="submit">Descartar proposta</button>
    </form>
  <?php endif; ?>
<?php endif; ?>
