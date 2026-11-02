<?php
/**
 * Documento coletado. O texto vem de fonte externa: exibido escapado, só como dado.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed> $doc
 */
use Concursei\Modules\Catalog\Format;

$view->share('noindex', true);
$labels = require __DIR__ . '/_labels.php';
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
[$textLabel, $textClass] = $labels['text'][$doc['text_status']];
$preview = $doc['text'] !== null ? mb_substr((string) $doc['text'], 0, 20000) : null;
?>
<?= $view->partial('admin/_nav', ['active' => 'fontes']) ?>
<p><a href="/admin/fontes/<?= (int) $doc['source_id'] ?>">← <?= e($doc['source_name']) ?></a></p>
<h1><?= e($doc['title'] ?? 'Documento #' . $doc['id']) ?></h1>

<dl class="facts card">
  <dt>Origem</dt><dd><a href="<?= e_url($doc['url']) ?>" rel="nofollow noopener noreferrer"><?= e($doc['url']) ?></a></dd>
  <dt>Fonte</dt><dd><?= e($doc['source_name']) ?> (<?= e($labels['kind'][$doc['source_kind']]) ?>)</dd>
  <dt>Coletado em</dt><dd><?= e(Format::localDate($doc['fetched_at'], $tz, true)) ?></dd>
  <dt>Arquivo</dt><dd><?= e($doc['mime_type']) ?> · <?= e(number_format((int) $doc['bytes'] / 1024, 0, ',', '.')) ?> KB<?= $doc['page_count'] !== null ? ' · ' . (int) $doc['page_count'] . ' página(s)' : '' ?> · <a href="/admin/documentos/<?= (int) $doc['id'] ?>/arquivo">baixar original</a></dd>
  <dt>SHA-256</dt><dd><code><?= e($doc['sha256']) ?></code></dd>
  <dt>Texto</dt><dd><span class="<?= e($textClass) ?>"><?= e($textLabel) ?></span><?= $doc['text_note'] !== null ? ' — ' . e($doc['text_note']) : '' ?></dd>
</dl>

<?php if ($doc['text_status'] === 'ready'): ?>
  <p><a class="button button-secondary" href="/admin/documentos/<?= (int) $doc['id'] ?>/extrair">Extração assistida por IA</a></p>
<?php endif; ?>

<?php if ($preview !== null): ?>
  <section aria-labelledby="texto">
    <h2 id="texto">Texto extraído<?= mb_strlen((string) $doc['text']) > 20000 ? ' (primeiros 20.000 caracteres)' : '' ?></h2>
    <pre class="document-text"><?= e($preview) ?></pre>
  </section>
<?php endif; ?>
