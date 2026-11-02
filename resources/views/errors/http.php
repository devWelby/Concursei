<?php
/**
 * @var int $status
 * @var string $message
 * @var array<string,list<string>> $fields
 * @var string $requestId
 * @var string|null $debug
 * @var \Concursei\Core\View\View $view
 */
$view->share('noindex', true);
$headings = [400 => 'Requisição inválida', 401 => 'Login necessário', 403 => 'Acesso negado', 404 => 'Página não encontrada',
    405 => 'Método não permitido', 409 => 'Conflito', 413 => 'Conteúdo muito grande', 422 => 'Dados inválidos',
    429 => 'Muitas tentativas', 503 => 'Serviço indisponível'];
?>
<section class="error-page" aria-labelledby="erro-titulo">
  <h1 id="erro-titulo"><?= e($headings[$status] ?? 'Erro') ?></h1>
  <p><?= e($message) ?></p>
  <?php if (!empty($fields)): ?>
    <ul class="field-errors">
      <?php foreach ($fields as $field => $errs): ?>
        <?php foreach ($errs as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($status === 401): ?><p><a class="button" href="/entrar">Entrar</a></p><?php endif; ?>
  <p class="muted">Código da requisição: <code><?= e($requestId) ?></code></p>
  <?php if (!empty($debug)): ?><pre class="debug"><?= e($debug) ?></pre><?php endif; ?>
  <p><a href="/">Voltar para a página inicial</a></p>
</section>
