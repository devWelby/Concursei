<?php
/**
 * Cookies em uso. Ao adicionar qualquer cookie (análise, anúncios), atualize esta tabela e exija consentimento antes.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var string $operator
 * @var string $contact
 * @var string $cookieName
 * @var bool $cookieSecure
 */
$view->share('canonical', $h->absoluteUrl('/cookies'));
?>
<article class="prose">
  <h1>Cookies</h1>
  <p>Cookies são pequenos arquivos que o site grava no seu navegador. O <?= e($h->appName()) ?> usa <strong>somente um cookie essencial</strong>, necessário para manter você conectado e proteger os formulários. Por ser essencial, ele não depende de consentimento, e o site não funciona corretamente se você bloqueá-lo.</p>

  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Nome</th><th scope="col">Para que serve</th><th scope="col">Duração</th><th scope="col">Tipo</th></tr></thead>
      <tbody>
        <tr>
          <td><code><?= e($cookieName) ?></code></td>
          <td>Identifica a sua sessão no servidor: mantém o login e o código de proteção dos formulários (CSRF). Só é criado quando você entra na conta ou envia um formulário.</td>
          <td>Até fechar o navegador; a sessão no servidor expira por inatividade</td>
          <td>Essencial, do próprio site. Inacessível a scripts (HttpOnly),<?= $cookieSecure ? ' enviado só por HTTPS' : '' ?> e não enviado em requisições vindas de outros sites (SameSite=Lax).</td>
        </tr>
      </tbody>
    </table>
  </div>

  <p>Não usamos cookies de análise, de publicidade nem de redes sociais. Se isso mudar, esta página será atualizada e esses cookies só serão gravados depois do seu consentimento, que poderá ser retirado a qualquer momento.</p>
  <p>Você pode apagar cookies nas configurações do seu navegador. Ao apagar o cookie de sessão, você sai da sua conta.</p>
  <p>Mais informações sobre o tratamento de dados estão na <a href="/privacidade">Política de privacidade</a>.</p>
</article>
