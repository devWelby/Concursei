# Decisões de arquitetura (ADR)

Formato: cada decisão tem contexto, decisão, alternativas e consequências. Decisões não são apagadas; quando mudam, uma nova entrada substitui a anterior.

---

## D-001 — PHP puro, monólito modular, sem framework

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** hospedagem compartilhada HostGator Plano M (Apache/cPanel, sem processos residentes, sem Node/Redis/Docker). O planejamento original citava WordPress/Next.js; o prompt técnico os substituiu por PHP puro.
- **Decisão:** núcleo próprio e pequeno em `app/Core` (Router com tabela explícita, Request/Response, contêiner com autowiring restrito, View com templates PHP) e módulos em `app/Modules/*` registrados explicitamente em `app/modules.php`. Controllers só orquestram; regras em Services, SQL em Repositories, autorização em Policies.
- **Alternativas:** Laravel/Symfony (proibidos pelo escopo; overhead no plano compartilhado); Slim + componentes (dependências a mais sem ganho essencial).
- **Consequências:** responsabilidade de manter segurança do núcleo (CSRF, sessão, headers) é nossa — coberta por testes. Sem mágica de ORM: SQL explícito e revisável.

## D-002 — Versão mínima PHP 8.2; produção recomendada 8.3/8.4

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** ambiente local tem PHP 8.2.12 (XAMPP). A versão da conta HostGator ainda não foi confirmada. PHP 8.2 recebe apenas correções de segurança até 31/12/2026.
- **Decisão:** `"php": ">=8.2"` e `config.platform.php = 8.2.12` no Composer (dependências resolvidas compatíveis com 8.2). Código evita recursos exclusivos de 8.3/8.4 e recursos depreciados em 8.4 (ex.: `session.sid_length`). **Produção deve usar 8.3 ou 8.4** se disponível no cPanel; com 8.2, planejar atualização antes do fim do suporte.
- **Alternativas:** exigir 8.4 (impede testar localmente hoje); rebaixar para 8.1 (sem suporte — proibido).
- **Consequências:** PHPUnit fica na série 11.5 (12.x exige 8.3). Revisar esta decisão ao confirmar a versão da conta.

## D-003 — Compatibilidade de banco: MariaDB 10.4+ / MySQL 5.7+

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** versão do banco na conta ainda não confirmada; local é MariaDB 10.4.32.
- **Decisão:** InnoDB, utf8mb4, `STRICT_ALL_TABLES`, UTC por sessão. Não usar `SKIP LOCKED`, CTE, funções de janela nem tipo JSON nativo obrigatório: JSON é `LONGTEXT` + `CHECK (JSON_VALID(...))` (validado também na aplicação). Claim de fila com `UPDATE ... ORDER BY ... LIMIT 1` + token de posse. `ON DUPLICATE KEY UPDATE` sem `VALUES()` (depreciado no MySQL 8.0.20+).
- **Consequências:** `CHECK` é ignorado no MySQL < 8.0.16 — a aplicação valida sempre. Testes de integração rodam contra MariaDB real, não SQLite.

## D-004 — Sessões no banco com ID hasheado e lock por sessão

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** exigência de logout real, invalidação de sessões em troca de senha/exclusão e expiração ociosa/absoluta.
- **Decisão:** `SessionHandlerInterface` próprio sobre a tabela `sessions`, armazenando só `SHA-256(id)`, `user_id` para logout global e `GET_LOCK` por sessão (equivalente ao lock do handler de arquivos). Cookie `__Host-concursei` em HTTPS, `HttpOnly`, `SameSite=Lax`, modo estrito. GC via Cron (`session:gc`), não probabilístico.
- **Alternativas:** sessões em arquivo (sem logout global), JWT/localStorage (rejeitado pelo prompt e pela superfície de XSS).
- **Consequências:** cada requisição com sessão faz 2–3 consultas leves; requisições externas longas devem chamar `session_write_close()` antes.

## D-005 — Papéis fixos com permissões em código

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** papéis exigidos: visitante, estudante, editor, administrador.
- **Decisão:** coluna `role` no usuário (enum) e mapa papel→permissões versionado em código e coberto por testes. Visitante = sem conta. Editor não configura segredos nem cria administradores.
- **Alternativas:** tabelas `roles`/`permissions` editáveis (flexibilidade não necessária; aumenta risco de escalonamento por configuração).
- **Consequências:** novo papel exige mudança de código + migração — aceitável para 4 papéis estáveis.

## D-006 — Criptografia apenas com primitivas padrão

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** extensão sodium ausente no ambiente local; OpenSSL presente.
- **Decisão:** senhas com `password_hash` Argon2id (fallback bcrypt com pré-hash HMAC para não truncar acima de 72 bytes); segredos em repouso (semente TOTP) com AES-256-GCM via OpenSSL; pseudonimização de IP/identificadores com HMAC-SHA256; subchaves derivadas do `APP_KEY` por HKDF com rótulos distintos. Tokens: 256 bits aleatórios, apenas SHA-256 no banco.
- **Consequências:** trocar `APP_KEY` invalida segredos cifrados (MFA) — rotação é operação planejada (documentada em OPERATIONS).

## D-007 — Rate limit persistente em tabela

- **Status:** aceita · **Data:** 2026-11-02
- **Decisão:** janela fixa por política nomeada (`config/security.php`), incremento atômico com `INSERT ... ON DUPLICATE KEY UPDATE`, chave = HMAC(escopo|identificador). Executa antes do trabalho caro.
- **Consequências:** janela fixa permite rajada de até 2× na virada da janela (limitação conhecida, aceitável para os volumes do plano compartilhado).

## D-008 — Banco de testes isolado no Windows

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** havia um `mysqld` de outro contexto em execução (porta 3307); o MariaDB padrão do XAMPP (3306) estava parado.
- **Decisão:** `scripts/dev/test-db.ps1` cria instância própria em `127.0.0.1:3310` com data dir em `%LOCALAPPDATA%\Concursei`. Os testes recusam bancos cujo nome não termine em `_test`.
- **Consequências:** nenhum dado de outros projetos é tocado. CI usará serviço MySQL/MariaDB próprio.

## D-009 — Materiais de planejamento fora do repositório público

- **Status:** aceita · **Data:** 2026-11-02
- **Decisão:** prompt, análise e o guia em PDF (material de terceiros) ficam locais via `.git/info/exclude`. Requisitos são rastreados em `docs/REQUIREMENTS.md` citando seções do prompt.
- **Consequências:** versionar esses arquivos exige decisão explícita do responsável.

## D-010 — Plugins do guia em uso nesta implementação

- **Status:** aceita · **Data:** 2026-11-02
- **Decisão:** usar Agent Skills (planejamento, Git, segurança, testes, revisão, documentação), Context7 (documentação atual de bibliotecas) e Graphify (mapa do código). **21st.dev** não foi instalado por decisão do responsável. **Strix** exige Docker e uma chave de LLM, indisponíveis neste ambiente: o pentest automatizado fica pendente, sem substituir os testes de segurança próprios.

## D-011 — E-mail transacional: PHPMailer + outbox no banco

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** o plano compartilhado limita envios por hora e não oferece fila; implementar SMTP/MIME à mão é fonte comum de falhas (STARTTLS, codificação, injeção de cabeçalho).
- **Decisão:** `phpmailer/phpmailer` 6.x (LGPL-2.1, mantido, sem dependências) só como transporte. Toda mensagem passa pela tabela `email_outbox` com chave de idempotência, reserva com `lock_token`, nova tentativa com espera exponencial e corpo apagado depois do envio (links com token não ficam no banco). Envio imediato quando possível; o Cron (`mail:send-pending`) cobre o restante. Transporte `capture` só existe fora de produção/homologação; em produção, sem SMTP, cadastro e recuperação aparecem como **indisponíveis** em vez de fingir sucesso.
- **Alternativas:** `mail()` nativo (sem TLS/autenticação controlados, entregabilidade ruim); Symfony Mailer (mais dependências para o mesmo resultado).
- **Consequências:** limite de envio configurável (`MAIL_BATCH_SIZE`); cota de 3 e-mails/hora por destinatário impede usar o site para spam.

## D-012 — MFA TOTP com OTPHP, sem biblioteca de QR code

- **Status:** aceita · **Data:** 2026-11-02
- **Decisão:** `spomky-labs/otphp` 11.x (MIT) para RFC 6238, com relógio PSR-20 injetado (testável). Chave de 160 bits (32 caracteres base32, RFC 4226 §4), cifrada em repouso com AES-256-GCM e AAD do usuário; ±1 passo de tolerância; anti-replay registrando o último passo aceito; 10 códigos de recuperação de uso único guardados como HMAC. A configuração usa chave digitável e link `otpauth://` (abre o autenticador no celular). **Sem QR code** nesta versão para não adicionar outra dependência de terceiros ao front-end.
- **Limite por conta:** 10 falhas de código a cada 15 minutos por conta, independente de IP (o contador de login por conta é zerado quando a senha confere, então sem esse limite quem tem a senha poderia tentar códigos a partir de vários IPs).
- **Obrigatoriedade:** editores e administradores só acessam `/admin` com MFA ativo; estudantes podem ativar opcionalmente.
- **Consequências:** perda do aparelho e dos códigos exige `php bin/console user:mfa-reset <email> --confirm` por quem opera o servidor, após verificar a identidade.

## D-013 — Contas da equipe criadas somente por CLI

- **Status:** aceita · **Data:** 2026-11-02
- **Decisão:** não existe seed com administrador nem senha padrão. `php bin/console user:create-admin --email=... --name=...` lê a senha do STDIN (nunca de argumento, que ficaria no histórico do shell) e aplica a mesma política de senha. `user:role` altera papéis; ambos registram auditoria.
- **Consequências:** o primeiro acesso da equipe é seguido da ativação obrigatória de MFA. Em Linux (hospedagem) a senha digitada não aparece no terminal (`stty -echo`); no Windows ela aparece, então prefira redirecionar de um arquivo temporário e apagá-lo em seguida.

## D-014 — Público adulto (18+)

- **Status:** aceita (premissa a confirmar pelo responsável) · **Data:** 2026-11-02
- **Contexto:** os simulados usam a API Gemini, cujos termos adicionais restringem o uso em serviços voltados a menores de 18 anos (conferir a versão vigente antes do lançamento). Além disso, a LGPD (art. 14) exige consentimento específico de um dos pais para dados de crianças e regras próprias para adolescentes.
- **Decisão:** o cadastro exige declarar 18 anos ou mais; a declaração fica registrada como consentimento `age_18`, junto com a versão dos termos (`config/legal.php`).
- **Consequências:** se o produto passar a atender menores, será preciso revisar a integração com IA, o fluxo de consentimento e os textos legais antes de remover a exigência.

## D-015 — Catálogo: cópia de trabalho, revisões imutáveis e modelo de leitura

- **Status:** aceita · **Data:** 2026-11-02
- **Contexto:** informação errada sobre prazo de inscrição prejudica quem estuda, e a equipe precisa corrigir dados sem expor rascunhos. O prompt exige publicação revisada, datas desconhecidas tratadas como desconhecidas, distinção entre vagas imediatas, cadastro de reserva e número não informado, e nada marcado como "verificado" com base em confiança declarada por IA.
- **Decisão:**
  - A equipe edita uma **cópia de trabalho** (`contests`, `contest_offers`, `contest_events`). Toda alteração, inclusive em cargos e datas, incrementa `contests.version` com verificação otimista (formulário desatualizado recebe 409) e devolve o concurso a rascunho.
  - Fluxo `draft → in_review → published`, sem enumeração única para o ciclo do concurso: a **situação informada** (`status`) é separada da **fase pública**, derivada por `ContestPhase` só de datas com precisão de dia, no fuso de exibição.
  - Publicar exige: estar em revisão, permissão `contests.publish`, a caixa "conferi com o documento oficial" marcada por uma pessoa, nota pública do que mudou e nenhuma pendência (fonte ausente, edital sem cargos, fim de inscrições antes do início). A revisão em duas pessoas é opcional (`CATALOG_FOUR_EYES`).
  - Cada publicação grava uma **revisão imutável** (`contest_revisions.snapshot`, JSON autocontido com nomes de órgão/banca/cargo) e reconstrói o **modelo de leitura** `contest_search*`. As páginas públicas leem só o modelo de leitura e as revisões, nunca a cópia de trabalho.
  - Datas têm precisão (`day`/`month`/`year`/`unknown`). O formulário tem um único campo, e o formato digitado define a precisão (DD/MM/AAAA, MM/AAAA, AAAA ou vazio). Mês e ano nunca aparecem como dia exato, nem abrem ou fecham inscrições.
  - Vagas: `NULL` significa "não informado"; `reserve` indica cadastro de reserva; 0 com reserva significa "somente cadastro de reserva".
  - Arquivar retira o concurso do catálogo público, mas mantém revisões e favoritos. Só rascunhos nunca publicados podem ser excluídos. O endereço (slug) fica fixo depois da primeira publicação.
  - O Cron diário `catalog:refresh` (depois da meia-noite de Brasília) recalcula fase, próxima data e fim das inscrições.
- **Alternativas consideradas:**
  - Editar direto a tabela pública: rejeitada, porque exporia rascunhos e não deixaria histórico.
  - Versionar cada tabela (temporal): rejeitada, por ser complexa demais para hospedagem compartilhada e MySQL 5.7.
  - Derivar a fase em tempo de consulta: rejeitada, porque os filtros por fase não usariam índice.
- **Consequências:**
  - Mudanças em órgãos, bancas e cargos só aparecem ao público na próxima publicação de cada concurso.
  - O histórico público mostra data, nota e marcação "Correção" de cada revisão.
  - Snapshots em formato novo exigirão uma migração de leitura (`SNAPSHOT_SCHEMA`).
