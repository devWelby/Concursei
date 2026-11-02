<?php
/**
 * Política de privacidade. Cada afirmação corresponde ao comportamento do código; ao mudar o tratamento
 * de dados, atualize este texto E incremente legal.privacy_version (novo aceite no cadastro).
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var string $operator
 * @var string $contact
 * @var string $privacyVersion
 * @var int $idleHours
 * @var int $absoluteDays
 */
$view->share('canonical', $h->absoluteUrl('/privacidade'));
?>
<article class="prose">
  <h1>Política de privacidade</h1>
  <p class="meta">Versão <?= e($privacyVersion) ?>. Esta página explica quais dados pessoais o <?= e($h->appName()) ?> trata, por quê, por quanto tempo e como você exerce seus direitos previstos na Lei Geral de Proteção de Dados (Lei 13.709/2018, LGPD).</p>

  <h2 id="responsavel">Quem é o responsável</h2>
  <?= $view->partial('pages/_operator', ['operator' => $operator, 'contact' => $contact]) ?>
  <p>O contato acima também atende como canal do encarregado pelo tratamento de dados pessoais.</p>

  <h2 id="dados">Quais dados tratamos</h2>
  <p>Você pode consultar concursos, o calendário e as páginas públicas <strong>sem criar conta</strong>. Nesse caso, não registramos quem você é. Para proteger o site contra abuso, contamos tentativas por endereço IP, guardado apenas como código irreversível (HMAC) e apagado diariamente após expirar.</p>
  <p>Se você criar uma conta, tratamos:</p>
  <ul>
    <li><strong>Dados de cadastro:</strong> e-mail, nome de exibição, fuso horário e a senha, guardada somente como hash irreversível (nunca em texto).</li>
    <li><strong>Aceites:</strong> versão dos termos e desta política aceitas, declaração de idade mínima e escolha sobre e-mails de novidades, com data e um código irreversível do IP.</li>
    <li><strong>Uso da conta:</strong> concursos que você acompanha, datas de criação, confirmação de e-mail, último acesso e troca de senha.</li>
    <li><strong>Segurança:</strong> sessão de acesso guardada no servidor, registro de eventos de segurança (entrada, saída, troca de senha ou e-mail, ativação de verificação em duas etapas) com código irreversível do IP e, se você ativar a verificação em duas etapas, a chave do aplicativo autenticador, cifrada.</li>
    <li><strong>E-mails enviados a você:</strong> endereço, assunto, situação e data do envio. O conteúdo de cada mensagem (que pode conter links de acesso) é apagado logo após o envio.</li>
  </ul>
  <p>Não pedimos CPF, endereço, telefone, dados de pagamento nem dados sensíveis.</p>

  <h2 id="finalidades">Para que usamos e com qual base legal</h2>
  <ul>
    <li><strong>Prestar o serviço que você pediu</strong> (conta, concursos acompanhados, painel): execução de contrato (art. 7º, V).</li>
    <li><strong>Segurança, prevenção de fraude e de abuso</strong> (limites de tentativas, registro de eventos, avisos de troca de senha ou e-mail): legítimo interesse (art. 7º, IX) e cumprimento de obrigação legal (art. 7º, II).</li>
    <li><strong>E-mails de novidades:</strong> somente com o seu consentimento (art. 7º, I), que você retira a qualquer momento em <a href="/conta">Minha conta</a> com a mesma facilidade com que concedeu.</li>
  </ul>
  <p>Não vendemos dados pessoais, não criamos perfil para publicidade e, hoje, o site não exibe anúncios nem usa ferramentas de análise de terceiros.</p>

  <h2 id="compartilhamento">Com quem compartilhamos</h2>
  <p>Somente com fornecedores necessários para o site funcionar, que tratam os dados em nosso nome:</p>
  <ul>
    <li><strong>Hospedagem</strong> (servidor e banco de dados onde o site roda).</li>
    <li><strong>Envio de e-mail</strong> (entrega das mensagens de confirmação, recuperação de senha e avisos de segurança).</li>
  </ul>
  <p>A equipe usa um serviço de inteligência artificial (Gemini, do Google, com servidores fora do Brasil) para ler <em>editais públicos</em> e sugerir datas e cargos, que uma pessoa confere antes de publicar. Hoje, nenhum dado pessoal de quem usa o site é enviado a serviços de inteligência artificial.</p>
  <p>Podemos fornecer dados a autoridades quando a lei ou uma ordem judicial exigir. Os nomes dos fornecedores em uso podem ser solicitados pelo contato acima.</p>

  <h2 id="prazos">Por quanto tempo guardamos</h2>
  <ul>
    <li><strong>Sessão de acesso:</strong> encerra após <?= (int) $idleHours ?> horas sem uso e, no máximo, <?= (int) $absoluteDays ?> dias depois da entrada.</li>
    <li><strong>Links de confirmação e de recuperação de senha:</strong> valem por até 24 horas (recuperação de senha: 1 hora) e são apagados até um dia depois de expirar.</li>
    <li><strong>Dados da conta:</strong> enquanto a conta existir. Ao excluir a conta, os dados de identificação são apagados ou anonimizados na hora.</li>
    <li><strong>Registros de segurança e de aceites:</strong> após a exclusão da conta, ficam desvinculados da sua identidade e são mantidos pelo tempo necessário para comprovar o cumprimento de obrigações legais e investigar incidentes.</li>
    <li><strong>Cópias de segurança do banco de dados:</strong> são substituídas periodicamente; uma conta excluída não é restaurada a partir delas, salvo para recuperar o serviço após um incidente.</li>
  </ul>

  <h2 id="direitos">Seus direitos e como exercê-los</h2>
  <p>A LGPD (art. 18) garante: confirmação e acesso aos dados, correção, anonimização, bloqueio ou eliminação de dados desnecessários, portabilidade, informação sobre compartilhamento, revogação do consentimento e revisão de decisões automatizadas.</p>
  <ul>
    <li><strong>Corrigir nome, fuso, e-mail e preferências:</strong> em <a href="/conta">Minha conta</a>.</li>
    <li><strong>Baixar uma cópia dos seus dados</strong> (formato JSON) ou <strong>excluir a conta:</strong> em <a href="/conta/dados">Meus dados</a>.</li>
    <li><strong>Demais pedidos:</strong> pelo contato do responsável. Responderemos em até 15 dias e podemos pedir confirmação de que a conta é sua.</li>
  </ul>
  <p>Você também pode apresentar reclamação à Autoridade Nacional de Proteção de Dados (ANPD).</p>

  <h2 id="seguranca">Segurança</h2>
  <p>Usamos conexão cifrada (HTTPS), senhas com hash forte, sessão no servidor com cookie protegido, limites de tentativas, verificação em duas etapas obrigatória para a equipe e cópias dos documentos coletados fora da área pública do site. Nenhuma medida é infalível: se ocorrer um incidente com risco relevante, comunicaremos você e a ANPD.</p>

  <h2 id="idade">Idade mínima</h2>
  <p>O cadastro é permitido somente a maiores de 18 anos. Não tratamos intencionalmente dados de crianças e adolescentes.</p>

  <h2 id="cookies">Cookies</h2>
  <p>Usamos apenas um cookie essencial de sessão. Veja os detalhes em <a href="/cookies">Cookies</a>.</p>

  <h2 id="mudancas">Mudanças nesta política</h2>
  <p>Quando esta política mudar, a versão acima será atualizada e as mudanças relevantes serão informadas no site.</p>
</article>
