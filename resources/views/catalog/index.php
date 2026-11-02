<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var \Concursei\Modules\Catalog\ContestFilters $filters
 * @var list<array<string,mixed>> $rows
 * @var int $total
 * @var int $perPage
 * @var list<array{slug:string,name:string}> $organizations
 * @var list<array{slug:string,name:string}> $boards
 * @var \DateTimeImmutable $today
 */
use Concursei\Modules\Catalog\Vocabulary;

$query = $filters->toQuery(['pagina' => null]);
$view->share('canonical', $h->absoluteUrl('/concursos' . ($query !== [] ? '?' . http_build_query($query) : '')));
if ($filters->q !== null) {
    // Buscas livres não são indexadas (conteúdo duplicado e infinito).
    $view->share('noindex', true);
}
$select = static function (string $name, string $label, array $options, ?string $current, ?string $empty): string {
    $html = '<div class="field"><label for="f-' . e($name) . '">' . e($label) . '</label><select id="f-' . e($name) . '" name="' . e($name) . '">';
    $html .= $empty !== null ? '<option value="">' . e($empty) . '</option>' : '';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    return $html . '</select></div>';
};
$ufOptions = ['nacional' => 'Nacional / várias UFs'] + Vocabulary::UFS;
?>
<h1>Concursos</h1>
<p class="lead">Concursos com datas e informações conferidas com a fonte oficial indicada em cada página. Confira sempre o edital.</p>

<form class="card" method="get" action="/concursos" role="search" aria-label="Filtrar concursos">
  <div class="field">
    <label for="f-q">Buscar</label>
    <input id="f-q" name="q" type="search" maxlength="100" value="<?= e($filters->q ?? '') ?>" placeholder="Órgão, cargo ou banca">
  </div>
  <div class="filters">
    <?= $select('situacao', 'Situação', array_map(static fn (array $s): string => $s['label'], Vocabulary::SITUATIONS), $filters->situation, 'Em aberto e previstos') ?>
    <?= $select('uf', 'UF', $ufOptions, $filters->uf, 'Todas') ?>
    <?= $select('regiao', 'Região', array_map(static fn (array $r): string => $r['label'], Vocabulary::REGIONS), $filters->region, 'Todas') ?>
    <?= $select('area', 'Área', Vocabulary::AREAS, $filters->area, 'Todas') ?>
    <?= $select('escolaridade', 'Escolaridade', Vocabulary::EDUCATION_LEVELS, $filters->level, 'Todas') ?>
    <?= $select('orgao', 'Órgão', array_column($organizations, 'name', 'slug'), $filters->organization, 'Todos') ?>
    <?= $select('banca', 'Banca', array_column($boards, 'name', 'slug'), $filters->board, 'Todas') ?>
    <?= $select('ordenar', 'Ordenar por', Vocabulary::SORTS, $filters->sort, null) ?>
  </div>
  <div class="filters-actions">
    <button class="button" type="submit">Filtrar</button>
    <?php if ($filters->hasFilters()): ?><a href="/concursos">Limpar filtros</a><?php endif; ?>
  </div>
</form>

<p role="status"><?= (int) $total ?> concurso(s) encontrado(s)<?= $filters->situation === null ? ' — concluídos e cancelados ficam fora; use o filtro "Situação" para vê-los' : '' ?>.</p>

<?php if ($rows === []): ?>
  <p>Nenhum concurso encontrado com esses filtros. <a href="/concursos">Ver todos</a> ou consulte o <a href="/calendario">calendário</a>.</p>
<?php else: ?>
  <ol class="contest-list">
    <?php foreach ($rows as $c): ?>
      <?= $view->partial('catalog/_card', ['c' => $c, 'today' => $today]) ?>
    <?php endforeach; ?>
  </ol>
  <?= $view->partial('partials/pagination', ['page' => $filters->page, 'total' => $total, 'perPage' => $perPage, 'path' => '/concursos', 'query' => $query]) ?>
<?php endif; ?>
