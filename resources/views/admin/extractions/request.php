<?php
/**
 * Pedido de extração assistida de um documento coletado.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed> $doc
 * @var array<int,string> $contests
 * @var list<array<string,mixed>> $history
 * @var bool $aiAvailable
 */
use Concursei\Modules\Catalog\Format;

$view->share('noindex', true);
$labels = require __DIR__ . '/_labels.php';
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
$ready = $doc['text_status'] === 'ready';
?>
<?= $view->partial('admin/_nav', ['active' => 'extracoes']) ?>
<p><a href="/admin/documentos/<?= (int) $doc['id'] ?>">← Documento #<?= (int) $doc['id'] ?></a></p>
<h1>Extração assistida por IA</h1>
<p><?= e($doc['title'] ?? $doc['url']) ?> — <?= e($doc['source_name']) ?></p>

<div class="alert alert-info">
  <p>A IA lê o texto deste documento e <strong>propõe</strong> datas, cargos e taxa, cada item com o trecho do documento que o sustenta.
    O sistema bloqueia itens cujo trecho não existe no documento ou não contém o valor. Nada é publicado:
    os itens aprovados vão para o rascunho do concurso e seguem a revisão normal.</p>
  <p>O texto do documento (público) é enviado à API do Gemini (Google). Não envie documentos com dados pessoais.</p>
</div>

<?php if ($doc['source_kind'] !== 'official'): ?>
  <div class="alert alert-warning">Fonte secundária: a proposta serve só para consulta e não pode ser aplicada ao concurso.</div>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Este documento não tem texto extraído (<?= e((string) $doc['text_status']) ?>). Cadastre os dados manualmente.</div>
<?php elseif ($contests === []): ?>
  <p>Cadastre o concurso antes de pedir a extração: a proposta é comparada com o rascunho dele.</p>
<?php else: ?>
  <form method="post" action="/admin/documentos/<?= (int) $doc['id'] ?>/extrair" class="card" novalidate>
    <?= $h->csrfField() ?>
    <?= $view->partial('partials/select', [
        'name' => 'contest_id', 'label' => 'Concurso a comparar', 'options' => $contests, 'required' => true,
        'selected' => (string) ($doc['source_contest_id'] ?? ''), 'placeholder' => 'Selecione',
        'hint' => 'A proposta é comparada com o rascunho deste concurso e só pode ser aplicada a ele.',
    ]) ?>
    <?php if (!$aiAvailable): ?>
      <p class="muted">IA não configurada neste servidor: só propostas já calculadas para o mesmo texto podem ser reaproveitadas.</p>
    <?php endif; ?>
    <button class="button" type="submit">Extrair com IA</button>
  </form>
<?php endif; ?>

<section aria-labelledby="historico">
  <h2 id="historico">Propostas deste documento</h2>
  <?php if ($history === []): ?>
    <p>Nenhuma proposta ainda.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Proposta</th><th scope="col">Concurso</th><th scope="col">Pedida em</th><th scope="col">Situação</th></tr></thead>
        <tbody>
          <?php foreach ($history as $x): ?>
            <?php [$statusLabel, $statusClass] = $labels['status'][$x['status']]; ?>
            <tr>
              <td><a href="/admin/extracoes/<?= (int) $x['id'] ?>">#<?= (int) $x['id'] ?></a><?= $x['reused_from'] !== null ? ' <span class="meta">(reaproveitada)</span>' : '' ?></td>
              <td><?= e($x['contest_title'] ?? 'Concurso removido') ?></td>
              <td><?= e(Format::localDate($x['created_at'], $tz, true)) ?></td>
              <td><span class="<?= e($statusClass) ?>"><?= e($statusLabel) ?></span><?= $x['status'] === 'failed' && $x['error'] !== null ? '<br><span class="meta">' . e($x['error']) . '</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
