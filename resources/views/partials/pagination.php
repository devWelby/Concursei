<?php
/**
 * Paginação por links (funciona sem JavaScript).
 * @var int $page
 * @var int $total
 * @var int $perPage
 * @var string $path caminho local da listagem
 * @var array<string,string|int> $query parâmetros atuais, sem "pagina"
 */
$pages = max(1, (int) ceil($total / $perPage));
if ($pages <= 1) {
    return;
}
$link = static function (int $p) use ($path, $query): string {
    $q = $query;
    if ($p > 1) {
        $q['pagina'] = $p;
    } else {
        unset($q['pagina']);
    }
    return $path . ($q !== [] ? '?' . http_build_query($q) : '');
};
?>
<nav class="pagination" aria-label="Paginação">
  <?php if ($page > 1): ?><a href="<?= e_url($link($page - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
  <span aria-current="page">Página <?= (int) $page ?> de <?= (int) $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= e_url($link($page + 1)) ?>" rel="next">Próxima</a><?php endif; ?>
</nav>
