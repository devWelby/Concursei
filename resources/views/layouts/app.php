<?php
/**
 * Layout principal.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var string $content HTML já renderizado e escapado pelo template filho
 * @var string $cspNonce
 */
$title = isset($title) ? $title . ' · ' . $h->appName() : $h->appName();
$metaDescription = $metaDescription ?? 'Concursos públicos abertos e previstos, editais, calendário, simulados e plano de estudos.';
$currentUser = $currentUser ?? null;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<?php if (!empty($canonical)): ?><link rel="canonical" href="<?= e_url($canonical) ?>"><?php endif; ?>
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="stylesheet" href="<?= e($h->asset('css/app.css')) ?>">
<script src="<?= e($h->asset('js/app.js')) ?>" defer nonce="<?= e($cspNonce) ?>"></script>
</head>
<body>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="/"><?= e($h->appName()) ?></a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="menu-principal" data-nav-toggle>Menu</button>
    <nav id="menu-principal" class="main-nav" aria-label="Principal">
      <ul>
        <li><a href="/concursos">Concursos</a></li>
        <li><a href="/calendario">Calendário</a></li>
        <?php if ($currentUser !== null): ?>
          <li><a href="/painel">Meu painel</a></li>
          <li><a href="/conta">Minha conta</a></li>
          <?php if (in_array($currentUser['role'] ?? '', ['editor', 'admin'], true)): ?><li><a href="/admin">Administração</a></li><?php endif; ?>
          <li>
            <form method="post" action="/sair" class="inline-form">
              <?= $h->csrfField() ?>
              <button type="submit" class="link-button">Sair</button>
            </form>
          </li>
        <?php else: ?>
          <li><a href="/entrar">Entrar</a></li>
          <li><a class="button button-small" href="/cadastro">Criar conta</a></li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
</header>

<main id="conteudo" class="container" tabindex="-1">
<?php foreach ($h->flashes() as $type => $messages): ?>
  <?php foreach ($messages as $message): ?>
    <div class="alert alert-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($message) ?></div>
  <?php endforeach; ?>
<?php endforeach; ?>
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="container footer-inner">
    <p><?= e($h->appName()) ?> — informações atualizadas periodicamente a partir de fontes indicadas em cada página. Confira sempre o edital oficial.</p>
    <nav aria-label="Institucional">
      <ul>
        <li><a href="/sobre">Sobre e metodologia</a></li>
        <li><a href="/correcoes">Correções</a></li>
        <li><a href="/privacidade">Privacidade</a></li>
        <li><a href="/termos">Termos de uso</a></li>
        <li><a href="/cookies">Cookies</a></li>
      </ul>
    </nav>
  </div>
</footer>
</body>
</html>
