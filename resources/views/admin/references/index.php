<?php
/**
 * @var \Concursei\Core\View\View $view
 * @var string $kind orgaos|bancas|cargos
 * @var array{table:string,title:string,new:string} $cfg
 * @var list<array<string,mixed>> $rows
 */
use Concursei\Modules\Catalog\Vocabulary;

$view->share('noindex', true);
?>
<?= $view->partial('admin/_nav', ['active' => $kind]) ?>
<h1><?= e($cfg['title']) ?></h1>
<p><a class="button" href="/admin/<?= e($kind) ?>/novo"><?= e($cfg['new']) ?></a></p>

<?php if ($rows === []): ?>
  <p>Nenhum cadastro ainda.</p>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th scope="col">Nome</th>
          <?php if ($cfg['table'] === 'organizations'): ?><th scope="col">Esfera</th><th scope="col">UF</th><?php endif; ?>
          <?php if ($cfg['table'] === 'careers'): ?><th scope="col">Área</th><th scope="col">Escolaridade</th><?php endif; ?>
          <th scope="col">Endereço</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><a href="/admin/<?= e($kind) ?>/<?= (int) $row['id'] ?>"><?= e($row['name']) ?></a><?php if (!empty($row['acronym'])): ?> <span class="muted">(<?= e($row['acronym']) ?>)</span><?php endif; ?></td>
            <?php if ($cfg['table'] === 'organizations'): ?>
              <td><?= e(Vocabulary::label(Vocabulary::SPHERES, $row['sphere'])) ?></td>
              <td><?= e($row['uf'] ?? 'Nacional') ?></td>
            <?php endif; ?>
            <?php if ($cfg['table'] === 'careers'): ?>
              <td><?= e(Vocabulary::label(Vocabulary::AREAS, $row['area'])) ?></td>
              <td><?= e(Vocabulary::label(Vocabulary::EDUCATION_LEVELS, $row['education_level'])) ?></td>
            <?php endif; ?>
            <td><code><?= e($row['slug']) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
