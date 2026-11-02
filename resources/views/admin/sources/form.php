<?php
/**
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed>|null $row
 * @var list<array<string,mixed>> $documents
 * @var array<int,string> $organizations
 * @var array<int,string> $boards
 * @var array<int,string> $contests
 */
use Concursei\Modules\Catalog\Format;

$view->share('noindex', true);
$labels = require __DIR__ . '/_labels.php';
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
$r = $row ?? ['kind' => 'official', 'method' => 'page', 'check_every_hours' => 24, 'enabled' => 1];
$v = static fn (string $k): string => $h->old($k, (string) ($r[$k] ?? ''));
$enabled = $h->hasErrors() ? in_array($h->old('enabled'), ['1', 'on'], true) : (int) ($r['enabled'] ?? 1) === 1;
?>
<?= $view->partial('admin/_nav', ['active' => 'fontes']) ?>
<p><a href="/admin/fontes">← Fontes</a></p>
<h1><?= $row === null ? 'Nova fonte' : e($row['name']) ?></h1>

<?php if ($row !== null): ?>
  <?php [$healthText, $healthClass] = $labels['health'][$row['health']]; ?>
  <section class="card stack" aria-labelledby="coleta">
    <h2 id="coleta">Coleta</h2>
    <dl class="facts">
      <dt>Situação</dt><dd><span class="<?= e($healthClass) ?>"><?= e($healthText) ?></span><?= (int) $row['consecutive_failures'] > 0 ? ' · ' . (int) $row['consecutive_failures'] . ' falha(s) seguida(s)' : '' ?></dd>
      <?php if ($row['last_error'] !== null): ?><dt>Último erro</dt><dd><?= e($row['last_error']) ?></dd><?php endif; ?>
      <dt>Última verificação</dt><dd><?= e(Format::localDate($row['last_checked_at'], $tz, true)) ?></dd>
      <dt>Última mudança</dt><dd><?= e(Format::localDate($row['last_changed_at'], $tz, true)) ?></dd>
      <dt>Próxima verificação</dt><dd><?= e(Format::localDate($row['next_check_at'], $tz, true)) ?></dd>
    </dl>
    <form method="post" action="/admin/fontes/<?= (int) $row['id'] ?>/verificar" class="inline-form">
      <?= $h->csrfField() ?>
      <button class="button button-secondary" type="submit">Verificar agora</button>
    </form>
  </section>
<?php endif; ?>

<form method="post" action="/admin/fontes<?= $row !== null ? '/' . (int) $row['id'] : '' ?>" novalidate class="card">
  <?= $h->csrfField() ?>
  <?= $view->partial('partials/field', ['name' => 'name', 'label' => 'Nome', 'value' => $v('name'), 'hint' => 'Ex.: "Página do concurso no site do órgão".', 'attrs' => ['maxlength' => '200']]) ?>
  <?= $view->partial('partials/field', ['name' => 'url', 'label' => 'Endereço (https)', 'type' => 'url', 'value' => $v('url'), 'hint' => 'O domínio deste endereço passa a ser autorizado para o coletor.', 'attrs' => ['maxlength' => '2048']]) ?>
  <div class="grid grid-2">
    <?= $view->partial('partials/select', ['name' => 'kind', 'label' => 'Tipo', 'options' => $labels['kind'], 'selected' => (string) $r['kind'], 'required' => true]) ?>
    <?= $view->partial('partials/select', ['name' => 'method', 'label' => 'Modo de coleta', 'options' => $labels['method'], 'selected' => (string) $r['method'], 'required' => true]) ?>
  </div>
  <?= $view->partial('partials/field', ['name' => 'extra_hosts', 'label' => 'Domínios adicionais (opcional)', 'value' => $v('extra_hosts'), 'required' => false, 'hint' => 'Separados por vírgula, para onde a página aponta os PDFs ou redireciona. Ex.: arquivos.orgao.gov.br, *.banca.org.br', 'attrs' => ['maxlength' => '500']]) ?>
  <div class="grid grid-2">
    <?= $view->partial('partials/select', ['name' => 'organization_id', 'label' => 'Órgão (opcional)', 'options' => $organizations, 'selected' => (string) ($r['organization_id'] ?? ''), 'placeholder' => 'Nenhum']) ?>
    <?= $view->partial('partials/select', ['name' => 'exam_board_id', 'label' => 'Banca (opcional)', 'options' => $boards, 'selected' => (string) ($r['exam_board_id'] ?? ''), 'placeholder' => 'Nenhuma']) ?>
  </div>
  <?= $view->partial('partials/select', ['name' => 'contest_id', 'label' => 'Concurso acompanhado (opcional)', 'options' => $contests, 'selected' => (string) ($r['contest_id'] ?? ''), 'placeholder' => 'Nenhum']) ?>
  <?= $view->partial('partials/field', ['name' => 'check_every_hours', 'label' => 'Verificar a cada (horas)', 'type' => 'number', 'value' => $v('check_every_hours'), 'attrs' => ['min' => '1', 'max' => '720', 'inputmode' => 'numeric']]) ?>
  <?= $view->partial('partials/textarea', ['name' => 'terms_note', 'label' => 'Condições de uso observadas (opcional)', 'value' => $v('terms_note'), 'maxlength' => 500, 'hint' => 'Ex.: termos do site, robots.txt, limite de acesso combinado.']) ?>
  <div class="field">
    <label class="checkbox"><input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>> Coleta ativa</label>
  </div>
  <button class="button" type="submit">Salvar</button>
</form>

<?php if ($row !== null): ?>
  <section aria-labelledby="documentos">
    <h2 id="documentos">Documentos coletados</h2>
    <?php if ($documents === []): ?>
      <p>Nenhum documento coletado ainda.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">Documento</th><th scope="col">Coletado em</th><th scope="col">Texto</th></tr></thead>
          <tbody>
            <?php foreach ($documents as $d): ?>
              <?php
              [$textLabel, $textClass] = $labels['text'][$d['text_status']];
              $name = $d['title'] ?? basename((string) parse_url((string) $d['url'], PHP_URL_PATH));
              ?>
              <tr>
                <td><a href="/admin/documentos/<?= (int) $d['id'] ?>"><?= e($name !== '' ? $name : 'Documento #' . $d['id']) ?></a><br><span class="meta"><?= e($d['mime_type']) ?> · <?= e(number_format((int) $d['bytes'] / 1024, 0, ',', '.')) ?> KB</span></td>
                <td><?= e(Format::localDate($d['fetched_at'], $tz, true)) ?></td>
                <td><span class="<?= e($textClass) ?>"><?= e($textLabel) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
