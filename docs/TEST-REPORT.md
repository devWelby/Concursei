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

## Testes externos pendentes

| Integração | Situação |
| --- | --- |
| Gemini API (REST) | pendente — sem credencial |
| SMTP real | pendente — sem credencial |
| HostGator (PHP/MySQL/Cron reais) | pendente — sem acesso à conta |
| Strix (pentest automatizado) | pendente — requer Docker + chave de LLM |
