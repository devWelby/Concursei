<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<int,string> $organizations
 * @var array<int,string> $boards
 */
$view->share('noindex', true);
?>
<?= $view->partial('admin/_nav', ['active' => 'concursos']) ?>
<h1>Novo concurso</h1>
<p class="muted">O concurso nasce como rascunho e só aparece ao público depois de revisado e publicado.</p>
<form method="post" action="/admin/concursos" novalidate class="card">
  <?= $h->csrfField() ?>
  <?= $view->partial('admin/contests/_form', ['contest' => null, 'organizations' => $organizations, 'boards' => $boards]) ?>
  <button class="button" type="submit">Criar rascunho</button>
</form>
