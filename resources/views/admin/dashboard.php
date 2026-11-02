<?php
/**
 * @var \Concursei\Core\View\View $view
 * @var array{draft:int,in_review:int,published:int,archived:int} $counts
 * @var list<array<string,mixed>> $inReview
 * @var list<array<string,mixed>> $stale
 * @var int $reverifyDays
 */
use Concursei\Modules\Catalog\Format;

$view->share('noindex', true);
$labels = ['draft' => 'Rascunhos', 'in_review' => 'Em revisão', 'published' => 'Publicados', 'archived' => 'Arquivados'];
?>
<?= $view->partial('admin/_nav', ['active' => 'painel']) ?>
<h1>Administração</h1>

<ul class="stats" aria-label="Concursos por situação editorial">
  <?php foreach ($labels as $key => $label): ?>
    <li class="card stat"><a href="/admin/concursos?situacao=<?= e($key) ?>"><span class="stat-number"><?= (int) $counts[$key] ?></span> <?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<div class="grid grid-2">
  <section class="card stack" aria-labelledby="revisao">
    <h2 id="revisao">Aguardando revisão</h2>
    <?php if ($inReview === []): ?>
      <p class="muted">Nada aguardando revisão.</p>
    <?php else: ?>
      <ul>
        <?php foreach ($inReview as $c): ?>
          <li><a href="/admin/concursos/<?= (int) $c['id'] ?>"><?= e($c['title']) ?></a> <span class="muted">— atualizado em <?= e(Format::date($c['updated_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card stack" aria-labelledby="conferencia">
    <h2 id="conferencia">Conferência pendente</h2>
    <p class="muted">Publicados ativos sem nova conferência com a fonte oficial há mais de <?= (int) $reverifyDays ?> dias.</p>
    <?php if ($stale === []): ?>
      <p>Tudo conferido no prazo.</p>
    <?php else: ?>
      <ul>
        <?php foreach ($stale as $c): ?>
          <li><a href="/admin/concursos/<?= (int) $c['id'] ?>"><?= e($c['title']) ?></a> <span class="muted">— última conferência: <?= e(Format::date($c['last_verified_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<p><a class="button" href="/admin/concursos/novo">Cadastrar concurso</a></p>
