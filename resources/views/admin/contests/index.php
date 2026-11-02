<?php
/**
 * @var \Concursei\Core\View\View $view
 * @var list<array<string,mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var ?string $workflow
 * @var string $q
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$view->share('noindex', true);
$workflows = ['draft' => 'Rascunho', 'in_review' => 'Em revisão', 'published' => 'Publicado', 'archived' => 'Arquivado'];
$badge = ['draft' => 'badge', 'in_review' => 'badge badge-warn', 'published' => 'badge badge-success'];
?>
<?= $view->partial('admin/_nav', ['active' => 'concursos']) ?>
<h1>Concursos</h1>

<form class="filters" method="get" action="/admin/concursos" role="search">
  <div class="field">
    <label for="f-q">Buscar</label>
    <input id="f-q" name="q" type="search" value="<?= e($q) ?>" maxlength="100" placeholder="Título ou órgão">
  </div>
  <div class="field">
    <label for="f-situacao">Situação editorial</label>
    <select id="f-situacao" name="situacao">
      <option value="">Todas</option>
      <?php foreach ($workflows as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $key === $workflow ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><button class="button button-secondary" type="submit">Filtrar</button></div>
</form>

<p><a class="button" href="/admin/concursos/novo">Cadastrar concurso</a> <span class="muted"><?= (int) $total ?> encontrado(s)</span></p>

<?php if ($rows === []): ?>
  <p>Nenhum concurso encontrado.</p>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Concurso</th><th scope="col">Situação informada</th><th scope="col">Fluxo</th><th scope="col">Atualizado</th><th scope="col">Conferido</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $c): ?>
          <?php $wf = $c['archived_at'] !== null ? 'archived' : (string) $c['workflow_status']; ?>
          <tr>
            <td><a href="/admin/concursos/<?= (int) $c['id'] ?>"><?= e($c['title']) ?></a><br><span class="muted"><?= e($c['organization_acronym'] ?? $c['organization_name']) ?></span></td>
            <td><?= e(Vocabulary::label(Vocabulary::STATUSES, $c['status'])) ?></td>
            <td><span class="<?= e($badge[$wf] ?? 'badge badge-error') ?>"><?= e($workflows[$wf]) ?></span><?php if ($wf === 'draft' && $c['published_revision_id'] !== null): ?> <span class="muted">(há versão publicada)</span><?php endif; ?></td>
            <td><?= e(Format::date($c['updated_at'])) ?></td>
            <td><?= e(Format::date($c['last_verified_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $view->partial('partials/pagination', ['page' => $page, 'total' => $total, 'perPage' => $perPage, 'path' => '/admin/concursos', 'query' => array_filter(['q' => $q, 'situacao' => $workflow])]) ?>
<?php endif; ?>
