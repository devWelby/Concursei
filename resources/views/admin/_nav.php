<?php
/**
 * @var string $active painel|concursos|orgaos|bancas|cargos|sistema
 * @var \Concursei\Core\View\View $view
 */
use Concursei\Shared\Auth\Permissions;

$items = ['painel' => ['/admin', 'Painel'], 'concursos' => ['/admin/concursos', 'Concursos'], 'orgaos' => ['/admin/orgaos', 'Órgãos'], 'bancas' => ['/admin/bancas', 'Bancas'], 'cargos' => ['/admin/cargos', 'Cargos']];
$role = (string) ($view->shared('currentUser')['role'] ?? '');
if (Permissions::allows($role, 'jobs.manage')) {
    $items['sistema'] = ['/admin/sistema', 'Sistema'];
}
?>
<nav class="sub-nav" aria-label="Administração">
  <ul>
    <?php foreach ($items as $key => [$href, $text]): ?>
      <li><a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($text) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
