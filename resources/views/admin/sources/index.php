<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var list<array<string,mixed>> $rows
 */
use Concursei\Modules\Catalog\Format;

$view->share('noindex', true);
$labels = require __DIR__ . '/_labels.php';
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
?>
<?= $view->partial('admin/_nav', ['active' => 'fontes']) ?>
<h1>Fontes</h1>
<p class="lead">Somente domínios cadastrados aqui podem ser acessados pelo coletor. Prefira órgão, banca e diário oficial; fonte secundária serve apenas para descobrir novidades, nunca para confirmar dados.</p>
<p><a class="button" href="/admin/fontes/nova">Cadastrar fonte</a></p>

<?php if ($rows === []): ?>
  <p>Nenhuma fonte cadastrada.</p>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Fonte</th><th scope="col">Tipo</th><th scope="col">Situação</th><th scope="col">Última verificação</th><th scope="col">Última mudança</th><th scope="col">Documentos</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $s): ?>
          <?php [$healthText, $healthClass] = $labels['health'][$s['health']]; ?>
          <tr>
            <td>
              <a href="/admin/fontes/<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a><br>
              <span class="meta"><?= e((string) parse_url((string) $s['url'], PHP_URL_HOST)) ?><?= $s['contest_title'] !== null ? ' · ' . e($s['contest_title']) : '' ?></span>
            </td>
            <td><?= e($labels['kind'][$s['kind']]) ?></td>
            <td>
              <?php if ((int) $s['enabled'] !== 1): ?><span class="badge">Desativada</span><?php else: ?><span class="<?= e($healthClass) ?>"><?= e($healthText) ?></span><?php endif; ?>
              <?php if ($s['last_error'] !== null): ?><br><span class="meta"><?= e($s['last_error']) ?></span><?php endif; ?>
            </td>
            <td><?= e(Format::localDate($s['last_checked_at'], $tz, true)) ?></td>
            <td><?= e(Format::localDate($s['last_changed_at'], $tz, true)) ?></td>
            <td><?= (int) $s['documents'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
