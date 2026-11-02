<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed> $contest
 * @var list<array<string,mixed>> $offers
 * @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $revisions
 * @var list<string> $blockers
 * @var bool $canPublish
 * @var bool $canReview
 * @var array<int,string> $organizations
 * @var array<int,string> $boards
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$view->share('noindex', true);
$id = (int) $contest['id'];
$base = '/admin/concursos/' . $id;
$version = (int) $contest['version'];
$archived = $contest['archived_at'] !== null;
$published = $contest['published_revision_id'] !== null;
$wf = (string) $contest['workflow_status'];
$wfLabel = ['draft' => 'Rascunho', 'in_review' => 'Em revisão', 'published' => 'Publicado'][$wf] ?? $wf;
$versionField = '<input type="hidden" name="version" value="' . $version . '">';
?>
<?= $view->partial('admin/_nav', ['active' => 'concursos']) ?>
<h1><?= e($contest['title']) ?></h1>
<p>
  <span class="badge<?= $wf === 'published' ? ' badge-success' : ($wf === 'in_review' ? ' badge-warn' : '') ?>"><?= e($wfLabel) ?></span>
  <?php if ($archived): ?><span class="badge badge-error">Arquivado</span><?php endif; ?>
  <?php if ($published): ?>
    <span class="muted">Revisão pública nº <?= (int) $contest['published_revision_no'] ?>, publicada em <?= e(Format::date($contest['published_at'])) ?>.</span>
    <?php if (!$archived): ?><a href="/concursos/<?= e($contest['slug']) ?>">Ver página pública</a><?php endif; ?>
  <?php endif; ?>
</p>
<?php if ($published && $wf !== 'published'): ?>
  <div class="alert alert-info" role="status">Há alterações ainda não publicadas. O público continua vendo a revisão nº <?= (int) $contest['published_revision_no'] ?>.</div>
<?php endif; ?>

<section class="card stack" aria-labelledby="fluxo">
  <h2 id="fluxo">Revisão e publicação</h2>
  <?php if ($blockers !== [] && !$archived): ?>
    <div class="alert alert-warning">
      <p><strong>Pendências para publicar:</strong></p>
      <ul><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($wf === 'draft' && !$archived): ?>
    <form method="post" action="<?= e($base) ?>/revisao" class="inline-form">
      <?= $h->csrfField() ?><?= $versionField ?>
      <button class="button" type="submit">Enviar para revisão</button>
    </form>
  <?php endif; ?>

  <?php if ($wf === 'in_review'): ?>
    <?php if ($canPublish): ?>
      <form method="post" action="<?= e($base) ?>/publicar" novalidate>
        <?= $h->csrfField() ?><?= $versionField ?>
        <?= $view->partial('partials/textarea', ['name' => 'change_note', 'label' => 'O que está sendo publicado ou alterado', 'value' => $published ? '' : 'Publicação inicial', 'maxlength' => 500, 'rows' => 2, 'required' => true, 'hint' => 'Aparece no histórico público de atualizações.']) ?>
        <?php if ($published): ?>
          <label class="checkbox"><input type="checkbox" name="is_correction" value="1"> Esta publicação corrige uma informação publicada antes (aparece como "Correção")</label>
        <?php endif; ?>
        <label class="checkbox"><input type="checkbox" name="verified" value="1" required> Conferi todas as informações com o documento oficial indicado como fonte.</label>
        <p><button class="button" type="submit">Publicar</button></p>
      </form>
    <?php endif; ?>
    <?php if ($canReview): ?>
      <form method="post" action="<?= e($base) ?>/devolver" class="inline-form">
        <?= $h->csrfField() ?><?= $versionField ?>
        <button class="button button-secondary" type="submit">Devolver para rascunho</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($canPublish && $published): ?>
    <form method="post" action="<?= e($base) ?>/<?= $archived ? 'reativar' : 'arquivar' ?>" class="inline-form">
      <?= $h->csrfField() ?>
      <button class="button button-secondary" type="submit"><?= $archived ? 'Reativar no catálogo público' : 'Arquivar (retirar do catálogo público)' ?></button>
    </form>
  <?php endif; ?>

  <?php if (!$published): ?>
    <form method="post" action="<?= e($base) ?>" class="inline-form" data-confirm="Excluir este rascunho definitivamente?">
      <?= $h->csrfField() ?><?= $h->methodField('DELETE') ?><?= $versionField ?>
      <button class="button button-danger" type="submit">Excluir rascunho</button>
    </form>
  <?php endif; ?>
</section>

<section class="stack" aria-labelledby="dados">
  <h2 id="dados">Dados principais</h2>
  <form method="post" action="<?= e($base) ?>" novalidate class="card">
    <?= $h->csrfField() ?><?= $versionField ?>
    <?= $view->partial('admin/contests/_form', ['contest' => $contest, 'organizations' => $organizations, 'boards' => $boards]) ?>
    <button class="button" type="submit">Salvar dados</button>
  </form>
</section>

<section class="stack" aria-labelledby="cargos">
  <h2 id="cargos">Cargos</h2>
  <p><a class="button button-secondary" href="<?= e($base) ?>/cargos/novo">Adicionar cargo</a></p>
  <?php if ($offers === []): ?>
    <p class="muted">Nenhum cargo cadastrado.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Cargo</th><th scope="col">Escolaridade</th><th scope="col">Vagas</th><th scope="col">Remuneração</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
        <tbody>
          <?php foreach ($offers as $o): ?>
            <tr>
              <td><?= e($o['title']) ?><?php if ($o['career_name'] !== null): ?><br><span class="muted"><?= e($o['career_name']) ?></span><?php endif; ?></td>
              <td><?= e(Vocabulary::label(Vocabulary::EDUCATION_LEVELS, $o['education_level'])) ?></td>
              <td><?= e(Format::vacancies($o['vacancies'], (bool) $o['reserve'])) ?></td>
              <td><?= e(Format::money($o['salary_cents'])) ?></td>
              <td class="actions">
                <a href="<?= e($base) ?>/cargos/<?= (int) $o['id'] ?>">Editar<span class="visually-hidden"> <?= e($o['title']) ?></span></a>
                <form method="post" action="<?= e($base) ?>/cargos/<?= (int) $o['id'] ?>" class="inline-form" data-confirm="Remover este cargo?">
                  <?= $h->csrfField() ?><?= $h->methodField('DELETE') ?><?= $versionField ?>
                  <button class="link-button" type="submit">Remover<span class="visually-hidden"> <?= e($o['title']) ?></span></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="stack" aria-labelledby="datas">
  <h2 id="datas">Datas</h2>
  <p><a class="button button-secondary" href="<?= e($base) ?>/datas/nova">Adicionar data</a></p>
  <?php if ($events === []): ?>
    <p class="muted">Nenhuma data cadastrada.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Evento</th><th scope="col">Data</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
        <tbody>
          <?php foreach ($events as $ev): ?>
            <?php $evLabel = $ev['label'] ?? Vocabulary::label(Vocabulary::EVENT_TYPES, $ev['type']); ?>
            <tr>
              <td><?= e($evLabel) ?><?php if ($ev['note'] !== null): ?><br><span class="muted"><?= e($ev['note']) ?></span><?php endif; ?></td>
              <td><?= e(Format::eventDate($ev['date_value'], $ev['date_precision'], $ev['time_local'])) ?></td>
              <td class="actions">
                <a href="<?= e($base) ?>/datas/<?= (int) $ev['id'] ?>">Editar<span class="visually-hidden"> <?= e($evLabel) ?></span></a>
                <form method="post" action="<?= e($base) ?>/datas/<?= (int) $ev['id'] ?>" class="inline-form" data-confirm="Remover esta data?">
                  <?= $h->csrfField() ?><?= $h->methodField('DELETE') ?><?= $versionField ?>
                  <button class="link-button" type="submit">Remover<span class="visually-hidden"> <?= e($evLabel) ?></span></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="stack" aria-labelledby="historico">
  <h2 id="historico">Histórico de publicações</h2>
  <?php if ($revisions === []): ?>
    <p class="muted">Ainda não publicado.</p>
  <?php else: ?>
    <ol reversed>
      <?php foreach ($revisions as $rev): ?>
        <li>
          <strong>Revisão <?= (int) $rev['revision_no'] ?></strong> — <?= e(Format::date($rev['published_at'])) ?>
          <?php if ((int) $rev['is_correction'] === 1): ?><span class="badge badge-warn">Correção</span><?php endif; ?>
          <?= $rev['published_by_name'] !== null ? '— ' . e($rev['published_by_name']) : '' ?>
          <br><?= e($rev['change_note']) ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
