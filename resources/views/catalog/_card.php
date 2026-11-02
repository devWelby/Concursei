<?php
/**
 * Cartão de concurso na listagem (dados do modelo de leitura público).
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var array<string,mixed> $c linha de contest_search
 * @var \DateTimeImmutable $today
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
$next = $c['next_event_on'] !== null ? Format::countdown((string) $c['next_event_on'], $today) : null;
?>
<li class="card contest-card">
  <h2><a href="/concursos/<?= e($c['slug']) ?>"><?= e($c['title']) ?></a></h2>
  <p class="meta">
    <?= e($c['organization_acronym'] ?? $c['organization_name']) ?>
    · <?= $c['exam_board_name'] !== null ? 'Banca ' . e($c['exam_board_name']) : 'Banca a definir' ?>
    · <?= e($c['uf'] ?? 'Nacional') ?>
  </p>
  <p><span class="<?= e(Format::phaseClass((string) $c['phase'])) ?>"><?= e(Vocabulary::label(Vocabulary::PHASES, $c['phase'])) ?></span></p>
  <ul class="meta">
    <?php if ($c['phase'] === 'registration_open' && $c['registration_ends_on'] !== null): ?>
      <li>Inscrições até <?= e(Format::eventDate($c['registration_ends_on'], 'day')) ?> (<?= e(Format::countdown((string) $c['registration_ends_on'], $today) ?? '') ?>)</li>
    <?php elseif ($c['next_event_on'] !== null): ?>
      <li>Próxima data: <?= e(Vocabulary::label(Vocabulary::EVENT_TYPES, $c['next_event_type'])) ?> em <?= e(Format::eventDate($c['next_event_on'], 'day')) ?><?= $next !== null ? ' (' . e($next) . ')' : '' ?></li>
    <?php endif; ?>
    <?php if ($c['vacancies_total'] !== null || (int) $c['has_reserve'] === 1): ?>
      <li><?= e(Format::vacancies($c['vacancies_total'] !== null ? (int) $c['vacancies_total'] : null, (int) $c['has_reserve'] === 1)) ?></li>
    <?php endif; ?>
    <?php if ($c['salary_max_cents'] !== null): ?><li>Remuneração de até <?= e(Format::money((int) $c['salary_max_cents'])) ?></li><?php endif; ?>
    <li>Atualizado em <?= e(Format::localDate($c['published_at'], $tz)) ?></li>
  </ul>
</li>
