# Relatório de testes

Registro honesto do que foi executado. "Fake/fixture" ≠ integração externa validada.

## Ambiente local de verificação

- Windows 11, PHP 8.2.12 (CLI, XAMPP), Composer 2.10.2, PHPUnit 11.5.57.
- MariaDB 10.4.32 em instância isolada (`127.0.0.1:3310`, banco `concursei_test`).
- Sem gitleaks e sem Docker na máquina (Strix não executado).

## Execuções

### 2026-11-02 — Fundação do núcleo

| Comando | Resultado |
| --- | --- |
| `php scripts/lint.php` | 57 arquivos, 0 erros |
| `vendor/bin/phpunit` | **OK — 45 testes, 136 asserções** (unit + integração em MariaDB real) |
| Camadas isoladas (worktree temporária por commit) | camada 1: 25 testes OK; camada 2: 35 testes OK |
| `composer audit` | sem advisories |
| `php scripts/check-repository-safety.php` com iscas sintéticas em repositório descartável | detectou `.env`, dump, chave Gemini, senha atribuída, e-mail real, chave privada; não acusou placeholders |
| Hook pre-commit real | bloqueou 2 commits (falso positivo corrigido na heurística; e-mail de domínio não reservado trocado por `.test`) |
| Smoke test HTTP (`php -S` + curl) | `GET /` 200 com CSP nonce, `X-Frame-Options: DENY`, request ID e cache público; `/saude` ok; `/.env` 404 |

Defeito encontrado pelos testes e corrigido antes do commit: regra `required` era ignorada em campo ausente (`isset` sobre valor `null`).

### 2026-11-02 — E-mail transacional e autenticação

| Comando | Resultado |
| --- | --- |
| `php scripts/lint.php` | 0 erros |
| `vendor/bin/phpunit` | **OK — 100 testes, 452 asserções** (unit + integração em MariaDB real) |
| Smoke test HTTP (`php -S` + curl, transporte `capture`) | cadastro → e-mail capturado → GET de confirmação não consome → POST confirma → reuso recusado → login errado com mensagem genérica → login → `/conta` com `private, no-store` e `X-Robots-Tag: noindex` → ativação de MFA com código TOTP real → saída → login exige 2ª etapa → código da ativação recusado (anti-replay) → código de recuperação aceito (9 restantes) |

Problemas encontrados e corrigidos nesta etapa:

- Chave TOTP padrão do OTPHP tinha 103 caracteres (64 bytes), inviável de digitar sem QR code → reduzida para 160 bits (32 caracteres).
- `View`/`TemplateHelper` guardavam dados da requisição anterior (flashes, `noindex`) quando o mesmo processo atende várias requisições → kernel passa a limpar o estado por requisição (teste de regressão em `KernelTest`).
- Sem limite por conta na 2ª etapa, quem tivesse a senha poderia tentar códigos TOTP a partir de vários IPs → limite de 10 falhas/15 min por conta.
- Nome digitado no cadastro ia para a caixa de e-mail de terceiros → nomes com aparência de link/endereço são omitidos nos e-mails.
- Hook pre-commit bloqueou 2 commits: regras de validação (`'password' => 'required|string|password|confirmed'`) eram lidas como segredo → heurística passou a reconhecer listas compostas só por regras do validador (iscas em repositório descartável confirmaram que segredos, inclusive misturados a nomes de regra, continuam detectados); a senha fraca proposital de um teste recebeu o marcador revisado `repo-safety: allow`.
- Arquivo-fragmento `app/Modules/Mail/.php` (sobra de um script de divisão de classes) quase entrou no commit; removido após inspeção.
- A instância MariaDB de teste ficou com tabelas de sistema corrompidas após encerramento abrupto do processo → reparada com `mysqlcheck --repair` (dados de teste apenas; procedimento documentado em PROGRESS).

| CLI no banco de desenvolvimento (senha via STDIN) | `user:create-admin` recusa senha fraca, senhas divergentes e e-mail duplicado (saída 1) e cria a conta no caso válido; `user:role` altera e recusa papel inválido; `user:mfa-reset` exige `--confirm`; `tokens:purge` ok |

Não coberto por teste automatizado: comandos CLI (verificados só pelo smoke test acima); envio por SMTP real.

### 2026-11-02 — CI (simulação local)

| Comando (cópia limpa do repositório) | Resultado |
| --- | --- |
| `composer validate --strict`, `composer install --no-plugins --no-scripts`, `composer audit --locked` | ok, sem advisories |
| `php scripts/lint.php` | 0 erros |
| `vendor/bin/phpunit --fail-on-skipped` | **OK — 100 testes** (MariaDB 10.4 local) |
| `check-repository-safety.php --tracked` e `--history` | ok |

As entradas MySQL 5.7/8.0 e PHP 8.3/8.4 da matriz só rodam no GitHub; ainda não foram executadas.

### 2026-11-02 — Catálogo de concursos

| Comando | Resultado |
| --- | --- |
| `php scripts/lint.php` | 161 arquivos, 0 erros |
| `composer audit --locked` | sem advisories |
| `vendor/bin/phpunit --fail-on-skipped` | **OK — 156 testes, 724 asserções** (unit 77/195; integração em MariaDB 10.4 real) |
| Novos testes | unit: `ContestPhaseTest` (16 cenários de fase), `FormatTest`, `ContestFiltersTest`; integração: `PublicationTest` (10), `AdminTest` (7), `PublicCatalogTest` (5), `FavoritesTest` (5), `DemoSeederTest` (2) |
| Smoke test no navegador (`composer serve` + Chrome headless, 1366 px e 500 px, dados de `dev:seed-demo`) | `/concursos`, detalhe e `/calendario` renderizam; fases e contagens corretas para a data local; datas passadas esmaecidas; sem rolagem horizontal no celular |
| CLI no banco de desenvolvimento | `migrate:status` (0003 aplicada), `dev:seed-demo` (4 concursos fictícios), `catalog:refresh` ok |

O que os testes cobrem:

- Só publicações revisadas chegam ao público; a página pública não muda com edições na cópia de trabalho antes da nova publicação.
- O formulário desatualizado recebe 409 sem sobrescrever dados.
- Itens de outro concurso respondem 404.
- O aluno recebe 403 nas ações do fluxo, e a equipe sem MFA é desviada para `/conta/seguranca`.
- A virada de dia usa o horário de Brasília (02h UTC ainda é o dia anterior).
- Datas só com mês ficam fora dos dias exatos, e datas desconhecidas não entram no calendário.
- Valores de filtro fora da allowlist e tentativas de injeção na busca são ignorados.
- Favoritos de outra pessoa respondem 404, e os favoritos são apagados com a conta.

Problemas encontrados e corrigidos nesta etapa:

- Três classes de exceção num único arquivo não seriam carregadas pelo autoload PSR-4 → um arquivo por classe.
- O auxiliar de teste criava o órgão padrão mesmo quando um órgão era informado (o `+=` avalia o valor padrão) → criação preguiçosa.
- "1000 vagas" sem separador de milhar e "Banca Banca …" duplicado, percebidos na inspeção visual → corrigidos, com teste.
- Duas expectativas de teste estavam erradas e foram ajustadas depois de conferir o comportamento:
  - POST sem token CSRF responde 403 (antes da autenticação), não 401.
  - A prova a 61 dias fica fora da janela de 60 dias do painel.

Não coberto por teste automatizado:

- Os comandos `catalog:refresh` e `dev:seed-demo` como CLI (as classes que eles chamam são testadas).
- As telas administrativas no navegador (cobertas por testes HTTP).

## Testes externos pendentes

| Integração | Situação |
| --- | --- |
| Gemini API (REST) | pendente — sem credencial |
| SMTP real | pendente — sem credencial |
| HostGator (PHP/MySQL/Cron reais) | pendente — sem acesso à conta |
| Strix (pentest automatizado) | pendente — requer Docker + chave de LLM |
