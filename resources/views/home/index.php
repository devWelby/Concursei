<?php
/** @var \Concursei\Core\View\TemplateHelper $h @var \Concursei\Core\View\View $view */
$view->share('canonical', $h->absoluteUrl('/'));
?>
<section class="hero" aria-labelledby="hero-titulo">
  <h1 id="hero-titulo">Encontre seu concurso e organize seus estudos</h1>
  <p class="lead">Concursos abertos, previstos e autorizados com fonte e data de verificação, simulados personalizados e um plano de estudo construído a partir dos seus erros.</p>
  <form class="search-form" method="get" action="/concursos" role="search">
    <label for="busca-home">Buscar concursos</label>
    <div class="search-row">
      <input id="busca-home" name="q" type="search" maxlength="100" placeholder="Órgão, cargo ou banca" autocomplete="off">
      <button class="button" type="submit">Buscar</button>
    </div>
  </form>
</section>
