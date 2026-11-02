<?php
/**
 * Registro público de correções (revisões publicadas marcadas como correção).
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var list<array<string,mixed>> $rows
 * @var string $operator
 * @var string $contact
 */
use Concursei\Modules\Catalog\Format;

$view->share('canonical', $h->absoluteUrl('/correcoes'));
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
?>
<h1>Correções</h1>
<p class="lead">Quando corrigimos uma informação já publicada, a correção aparece aqui e no histórico da página do concurso.</p>

<?php if ($rows === []): ?>
  <p>Nenhuma correção publicada até agora.</p>
<?php else: ?>
  <ul class="stack" aria-label="Correções publicadas">
    <?php foreach ($rows as $row): ?>
      <li class="card">
        <p><a href="/concursos/<?= e($row['slug']) ?>"><strong><?= e($row['title']) ?></strong></a> <span class="meta">— <?= e($row['organization_name']) ?></span></p>
        <p><?= e($row['change_note'] ?? 'Correção de informação publicada.') ?></p>
        <p class="meta">Revisão nº <?= (int) $row['revision_no'] ?>, publicada em <?= e(Format::localDate($row['published_at'], $tz)) ?>.</p>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="meta">Mostrando as 100 correções mais recentes.</p>
<?php endif; ?>

<h2 id="informar">Informar um erro</h2>
<p>Escreva para o contato do responsável indicando o concurso e, se possível, o trecho do documento oficial que mostra a informação correta.</p>
<?= $view->partial('pages/_operator', ['operator' => $operator, 'contact' => $contact]) ?>
