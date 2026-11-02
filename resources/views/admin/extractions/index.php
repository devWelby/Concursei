<?php
/**
 * Lista de propostas da IA.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var list<array<string,mixed>> $rows
 * @var ?string $status
 */
use Concursei\Modules\Catalog\Format;

$view->share('noindex', true);
$labels = require __DIR__ . '/_labels.php';
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
?>
<?= $view->partial('admin/_nav', ['active' => 'extracoes']) ?>
<h1>Propostas da IA</h1>
<p>Para pedir uma extração, abra um documento coletado em <a href="/admin/fontes">Fontes</a>.</p>

<nav class="sub-nav" aria-label="Filtrar por situação">
  <ul>
    <li><a href="/admin/extracoes"<?= $status === null ? ' aria-current="page"' : '' ?>>Todas</a></li>
    <?php foreach ($labels['status'] as $key => [$text]): ?>
      <li><a href="/admin/extracoes?status=<?= e($key) ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>><?= e($text) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>

<?php if ($rows === []): ?>
  <p>Nenhuma proposta<?= $status !== null ? ' nesta situação' : '' ?>.</p>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Proposta</th><th scope="col">Documento</th><th scope="col">Concurso</th><th scope="col">Pedida em</th><th scope="col">Situação</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $x): ?>
          <?php
          [$statusLabel, $statusClass] = $labels['status'][$x['status']];
          $docName = $x['document_title'] ?? basename((string) parse_url((string) $x['document_url'], PHP_URL_PATH));
          ?>
          <tr>
            <td><a href="/admin/extracoes/<?= (int) $x['id'] ?>">#<?= (int) $x['id'] ?></a></td>
            <td><a href="/admin/documentos/<?= (int) $x['document_id'] ?>"><?= e($docName !== '' ? $docName : 'Documento #' . $x['document_id']) ?></a><?= $x['source_kind'] !== 'official' ? ' <span class="badge">fonte secundária</span>' : '' ?></td>
            <td><?= e($x['contest_title'] ?? 'Concurso removido') ?></td>
            <td><?= e(Format::localDate($x['created_at'], $tz, true)) ?></td>
            <td><span class="<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
