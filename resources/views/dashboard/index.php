<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var \Concursei\Shared\Auth\AuthUser $user
 * @var \DateTimeImmutable $today
 * @var list<array<string,mixed>> $rows
 * @var list<array<string,mixed>> $upcoming
 * @var int $upcomingDays
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$view->share('noindex', true);
?>
<h1>Meu painel</h1>
<p class="lead">Olá, <?= e($user->displayName) ?>. Aqui ficam as datas dos concursos que você acompanha.</p>

<section class="card stack" aria-labelledby="proximas">
  <h2 id="proximas">Próximas datas (<?= (int) $upcomingDays ?> dias)</h2>
  <?php if ($upcoming === []): ?>
    <p class="muted">Nenhuma data com dia definido nos próximos <?= (int) $upcomingDays ?> dias.</p>
  <?php else: ?>
    <ul class="timeline">
      <?php foreach ($upcoming as $e): ?>
        <li>
          <span class="when"><?= e(Format::eventDate((string) $e['date_value'], 'day', $e['time_local'])) ?> <span class="meta">(<?= e(Format::countdown((string) $e['date_value'], $today) ?? '') ?>)</span></span>
          <span><?= e($e['label'] ?? Vocabulary::label(Vocabulary::EVENT_TYPES, $e['type'])) ?> — <a href="/concursos/<?= e($e['slug']) ?>"><?= e($e['title']) ?></a></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="stack" aria-labelledby="acompanhados">
  <h2 id="acompanhados">Concursos acompanhados</h2>
  <?php if ($rows === []): ?>
    <p>Você ainda não acompanha nenhum concurso. <a href="/concursos">Explore o catálogo</a> e use "Acompanhar este concurso" na página de cada um.</p>
  <?php else: ?>
    <ul class="contest-list">
      <?php foreach ($rows as $c): ?>
        <li class="card contest-card">
          <h3><?php if ((int) $c['archived'] === 1): ?><?= e($c['title']) ?><?php else: ?><a href="/concursos/<?= e($c['slug']) ?>"><?= e($c['title']) ?></a><?php endif; ?></h3>
          <?php if ((int) $c['archived'] === 1): ?>
            <p><span class="badge badge-error">Retirado do catálogo</span> <span class="meta">A equipe retirou este concurso da lista pública.</span></p>
          <?php else: ?>
            <p class="meta"><?= e($c['organization_acronym'] ?? $c['organization_name']) ?> · <?= e($c['uf'] ?? 'Nacional') ?></p>
            <p><span class="<?= e(Format::phaseClass((string) $c['phase'])) ?>"><?= e(Vocabulary::label(Vocabulary::PHASES, $c['phase'])) ?></span></p>
            <?php if ($c['phase'] === 'registration_open' && $c['registration_ends_on'] !== null): ?>
              <p>Inscrições até <?= e(Format::eventDate((string) $c['registration_ends_on'], 'day')) ?> (<?= e(Format::countdown((string) $c['registration_ends_on'], $today) ?? '') ?>)</p>
            <?php elseif ($c['next_event_on'] !== null): ?>
              <p>Próxima data: <?= e(Vocabulary::label(Vocabulary::EVENT_TYPES, $c['next_event_type'])) ?> em <?= e(Format::eventDate((string) $c['next_event_on'], 'day')) ?> (<?= e(Format::countdown((string) $c['next_event_on'], $today) ?? '') ?>)</p>
            <?php else: ?>
              <p class="muted">Sem próxima data com dia definido.</p>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" action="/favoritos/<?= (int) $c['favorite_id'] ?>" class="inline-form">
            <?= $h->csrfField() ?><?= $h->methodField('DELETE') ?>
            <input type="hidden" name="voltar" value="painel">
            <button class="link-button" type="submit">Deixar de acompanhar<span class="visually-hidden"> <?= e($c['title']) ?></span></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
