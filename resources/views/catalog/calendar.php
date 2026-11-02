<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var \DateTimeImmutable $month primeiro dia do mês exibido
 * @var \DateTimeImmutable $today
 * @var \Concursei\Modules\Catalog\ContestFilters $filters
 * @var ?string $type
 * @var array{days:array<string,list<array<string,mixed>>>,month:list<array<string,mixed>>} $data
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$keep = array_filter(['uf' => $filters->uf, 'regiao' => $filters->region, 'tipo' => $type], static fn ($v): bool => $v !== null);
$link = static fn (DateTimeImmutable $m): string => '/calendario?' . http_build_query(['mes' => $m->format('Y-m')] + $keep);
$prev = $month->modify('-1 month');
$next = $month->modify('+1 month');
$todayStr = $today->format('Y-m-d');
$view->share('canonical', $h->absoluteUrl('/calendario?mes=' . $month->format('Y-m')));
if ($keep !== []) {
    $view->share('noindex', true);
}
$eventName = static fn (array $e): string => (string) ($e['label'] ?? Vocabulary::label(Vocabulary::EVENT_TYPES, $e['type']));
?>
<h1>Calendário de concursos</h1>

<nav class="month-nav" aria-label="Meses">
  <?php if ((int) $prev->format('Y') >= 2000): ?><a href="<?= e_url($link($prev)) ?>" rel="prev">← <?= e(Format::monthLabel($prev)) ?></a><?php else: ?><span></span><?php endif; ?>
  <h2 aria-live="polite"><?= e(Format::monthLabel($month)) ?></h2>
  <?php if ((int) $next->format('Y') <= 2099): ?><a href="<?= e_url($link($next)) ?>" rel="next"><?= e(Format::monthLabel($next)) ?> →</a><?php else: ?><span></span><?php endif; ?>
</nav>

<form class="card" method="get" action="/calendario" aria-label="Filtrar calendário">
  <input type="hidden" name="mes" value="<?= e($month->format('Y-m')) ?>">
  <div class="filters">
    <div class="field">
      <label for="f-uf">UF</label>
      <select id="f-uf" name="uf">
        <option value="">Todas</option>
        <?php foreach (['nacional' => 'Nacional / várias UFs'] + Vocabulary::UFS as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= $k === $filters->uf ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-regiao">Região</label>
      <select id="f-regiao" name="regiao">
        <option value="">Todas</option>
        <?php foreach (Vocabulary::REGIONS as $k => $r): ?>
          <option value="<?= e($k) ?>"<?= $k === $filters->region ? ' selected' : '' ?>><?= e($r['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-tipo">Tipo de data</label>
      <select id="f-tipo" name="tipo">
        <option value="">Todas</option>
        <?php foreach (Vocabulary::EVENT_TYPES as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= $k === $type ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><button class="button button-secondary" type="submit">Filtrar</button></div>
  </div>
</form>

<?php if ($data['days'] === [] && $data['month'] === []): ?>
  <p>Nenhuma data publicada para <?= e(Format::monthLabel($month)) ?> com esses filtros.</p>
<?php endif; ?>

<?php foreach ($data['days'] as $date => $items): ?>
  <section class="calendar-day<?= $date < $todayStr ? ' is-past' : '' ?>" aria-labelledby="dia-<?= e($date) ?>">
    <h3 id="dia-<?= e($date) ?>"><?= e(Format::eventDate($date, 'day')) ?>, <?= e(Format::weekday($date)) ?><?= $date === $todayStr ? ' (hoje)' : '' ?></h3>
    <ul>
      <?php foreach ($items as $e): ?>
        <li>
          <?= e($eventName($e)) ?><?= $e['time_local'] !== null ? ' às ' . e(Format::timeOfDay((string) $e['time_local'])) : '' ?>:
          <a href="/concursos/<?= e($e['slug']) ?>"><?= e($e['title']) ?></a> <span class="meta">(<?= e($e['uf'] ?? 'Nacional') ?>)</span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>

<?php if ($data['month'] !== []): ?>
  <section aria-labelledby="sem-dia">
    <h2 id="sem-dia">Previstos para <?= e(mb_strtolower(Format::monthLabel($month))) ?>, sem dia definido</h2>
    <ul>
      <?php foreach ($data['month'] as $e): ?>
        <li><?= e($eventName($e)) ?>: <a href="/concursos/<?= e($e['slug']) ?>"><?= e($e['title']) ?></a> <span class="meta">(<?= e($e['uf'] ?? 'Nacional') ?>)</span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<p class="source-note">Só aparecem datas de concursos publicados. Datas sem dia definido não são mostradas como dia exato.</p>
