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

## Testes externos pendentes

| Integração | Situação |
| --- | --- |
| Gemini API (REST) | pendente — sem credencial |
| SMTP real | pendente — sem credencial |
| HostGator (PHP/MySQL/Cron reais) | pendente — sem acesso à conta |
| Strix (pentest automatizado) | pendente — requer Docker + chave de LLM |
