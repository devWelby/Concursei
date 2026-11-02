<?php /** @var \Concursei\Core\View\View $view @var list<string> $codes */ $view->share('noindex', true); ?>
<section class="form-narrow stack" aria-labelledby="titulo">
  <h1 id="titulo">Códigos de recuperação</h1>
  <div class="alert alert-warning" role="status">Guarde estes códigos agora, em local seguro (impressos ou num gerenciador de senhas). Eles não serão exibidos novamente.</div>
  <p>Cada código funciona <strong>uma única vez</strong> no lugar do código do aplicativo, caso você perca o celular.</p>
  <ul class="recovery-codes">
    <?php foreach ($codes as $code): ?><li><code><?= e($code) ?></code></li><?php endforeach; ?>
  </ul>
  <p class="no-print"><button class="button button-secondary" type="button" data-print>Imprimir</button>
    <a class="button" href="/conta/seguranca">Já guardei os códigos</a></p>
</section>
