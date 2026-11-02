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
