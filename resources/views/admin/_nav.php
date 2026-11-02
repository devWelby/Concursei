<?php
/** @var string $active painel|concursos|orgaos|bancas|cargos */
$items = ['painel' => ['/admin', 'Painel'], 'concursos' => ['/admin/concursos', 'Concursos'], 'orgaos' => ['/admin/orgaos', 'Órgãos'], 'bancas' => ['/admin/bancas', 'Bancas'], 'cargos' => ['/admin/cargos', 'Cargos']];
?>
<nav class="sub-nav" aria-label="Administração">
  <ul>
    <?php foreach ($items as $key => [$href, $text]): ?>
      <li><a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($text) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
