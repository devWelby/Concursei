<?php
/**
 * Termos de uso. Alterou o texto → incremente legal.terms_version (novo aceite no cadastro).
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var string $operator
 * @var string $contact
 * @var string $termsVersion
 * @var int $minimumAge
 */
$view->share('canonical', $h->absoluteUrl('/termos'));
$name = $h->appName();
?>
<article class="prose">
  <h1>Termos de uso</h1>
  <p class="meta">Versão <?= e($termsVersion) ?>. Ao criar uma conta, você declara ter lido e aceito estes termos e a <a href="/privacidade">Política de privacidade</a>.</p>

  <h2 id="servico">1. O que é o serviço</h2>
  <p>O <?= e($name) ?> reúne informações públicas sobre concursos (órgãos, bancas, cargos, vagas, datas e links para os documentos oficiais) e oferece ferramentas para acompanhar concursos e organizar os estudos.</p>
  <p>O <?= e($name) ?> é independente: <strong>não tem vínculo com órgãos públicos nem com bancas organizadoras</strong> e não realiza inscrições.</p>

  <h2 id="informacao">2. Informação de caráter auxiliar: confira sempre o edital</h2>
  <p>Publicamos cada concurso a partir de fontes indicadas na página dele, com conferência pela equipe e histórico público de alterações. Mesmo assim, editais mudam (retificações) e erros podem acontecer. <strong>O edital e os comunicados oficiais prevalecem</strong> sobre qualquer informação do site. Antes de se inscrever, pagar taxa ou planejar viagem, confira as datas e as regras no documento oficial.</p>
  <p>Datas sem dia definido aparecem como "previsto" ou "a definir", nunca como data exata. Parte dos dados pode ter sido extraída com auxílio de inteligência artificial, sempre revisada por uma pessoa antes da publicação.</p>
  <p>Encontrou um erro? Escreva para o contato do responsável. As correções publicadas ficam registradas em <a href="/correcoes">Correções</a>.</p>

  <h2 id="conta">3. Sua conta</h2>
  <ul>
    <li>A conta é pessoal e exige ter <?= (int) $minimumAge ?> anos ou mais.</li>
    <li>Informe um e-mail que seja seu e mantenha sua senha em sigilo. Avise-nos se suspeitar de uso indevido.</li>
    <li>Você pode baixar seus dados ou excluir a conta a qualquer momento em <a href="/conta/dados">Meus dados</a>.</li>
  </ul>

  <h2 id="uso">4. Uso permitido</h2>
  <p>Não é permitido:</p>
  <ul>
    <li>tentar acessar áreas ou dados de outras pessoas, contornar limites de segurança ou explorar falhas (se encontrar uma, avise-nos de forma responsável pelo contato);</li>
    <li>fazer coleta automatizada em massa que prejudique o funcionamento do site;</li>
    <li>usar o serviço para enviar spam, conteúdo ilegal ou para se passar por outra pessoa.</li>
  </ul>
  <p>Em caso de violação, podemos limitar ou encerrar o acesso, sem prejuízo das medidas legais cabíveis.</p>

  <h2 id="conteudo">5. Conteúdo</h2>
  <p>Editais e comunicados oficiais são documentos públicos dos respectivos órgãos e bancas; o site aponta para as fontes originais. Os textos, a organização das informações e o código do site pertencem ao responsável pelo <?= e($name) ?>.</p>

  <h2 id="responsabilidade">6. Responsabilidade</h2>
  <p>Trabalhamos para manter o serviço disponível e as informações corretas, mas o site pode ficar fora do ar para manutenção ou por falhas de terceiros. Nada nestes termos afasta os direitos garantidos pelo Código de Defesa do Consumidor.</p>

  <h2 id="mudancas">7. Mudanças nos termos</h2>
  <p>Estes termos podem ser atualizados; a versão vigente fica sempre nesta página. Mudanças relevantes serão informadas no site.</p>

  <h2 id="lei">8. Lei aplicável e contato</h2>
  <p>Estes termos seguem a lei brasileira. Fica eleito o foro do domicílio de quem usa o serviço.</p>
  <?= $view->partial('pages/_operator', ['operator' => $operator, 'contact' => $contact]) ?>
</article>
