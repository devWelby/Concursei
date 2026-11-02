<?php
/**
 * Sobre e metodologia: como os dados chegam ao site (fonte, conferência humana, IA só como apoio).
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var string $operator
 * @var string $contact
 */
$view->share('canonical', $h->absoluteUrl('/sobre'));
$name = $h->appName();
?>
<article class="prose">
  <h1>Sobre o <?= e($name) ?> e metodologia</h1>
  <p class="lead">O <?= e($name) ?> ajuda quem estuda para concursos a encontrar editais, acompanhar prazos e organizar os estudos, com cada informação ligada à sua fonte.</p>

  <h2 id="independencia">Independência</h2>
  <p>O site não tem vínculo com órgãos públicos nem com bancas organizadoras e não realiza inscrições. O edital e os comunicados oficiais sempre prevalecem.</p>

  <h2 id="como">Como as informações são publicadas</h2>
  <ol>
    <li><strong>Fonte:</strong> cada concurso indica o documento oficial usado na conferência (edital, retificação ou comunicado) e o link para o site do órgão ou da banca.</li>
    <li><strong>Coleta:</strong> a equipe cadastra as páginas oficiais a acompanhar. O site verifica essas páginas periodicamente e guarda uma cópia dos documentos novos para consulta interna.</li>
    <li><strong>Apoio de inteligência artificial:</strong> a IA pode ler um edital e <em>sugerir</em> datas, cargos e taxa. Cada sugestão precisa citar o trecho do documento que a sustenta, e o sistema recusa sugestões cujo trecho não existe no documento. A IA nunca publica nada.</li>
    <li><strong>Conferência humana:</strong> uma pessoa da equipe confere as informações com o documento oficial e só então publica. Sites de notícias servem apenas como pista e nunca substituem a fonte oficial.</li>
    <li><strong>Histórico:</strong> cada publicação gera uma revisão com data e descrição do que mudou, visível na página do concurso. Correções de informações publicadas aparecem marcadas e também em <a href="/correcoes">Correções</a>.</li>
  </ol>

  <h2 id="datas">Como mostramos datas e vagas</h2>
  <ul>
    <li>Datas sem dia definido aparecem como "previsto" (por exemplo, "junho de 2027 (previsto)") ou "a definir", nunca como dia exato.</li>
    <li>A situação "inscrições abertas" só é calculada a partir de datas exatas, no horário de Brasília.</li>
    <li>Vagas não informadas aparecem como "não informado"; cadastro de reserva aparece separado das vagas imediatas.</li>
  </ul>

  <h2 id="erros">Encontrou um erro?</h2>
  <p>Escreva para o contato abaixo indicando o concurso e, se possível, o trecho do documento oficial. Depois de conferida, a correção é publicada com registro no histórico.</p>
  <?= $view->partial('pages/_operator', ['operator' => $operator, 'contact' => $contact]) ?>

  <h2 id="anuncios">Anúncios</h2>
  <p>Hoje o site não exibe anúncios. Se passar a exibir, eles serão identificados como publicidade e separados do conteúdo, e cookies de publicidade só serão usados com o seu consentimento.</p>
</article>
