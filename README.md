# Concursei

Plataforma brasileira de concursos públicos em **PHP puro (sem framework)** com MySQL/MariaDB, desenhada para hospedagem compartilhada (HostGator Plano M): catálogo e calendário de concursos com fonte e verificação, simulados personalizados, correção no servidor, mapa de estudo derivado dos erros e planejador.

> Estado atual: em desenvolvimento. Veja o que já funciona em [docs/PROGRESS.md](docs/PROGRESS.md) e a rastreabilidade em [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md).

## Requisitos

- PHP **8.2+** (produção: preferir 8.3/8.4 — ver [D-002](docs/DECISIONS.md)) com extensões `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`, `fileinfo`, `ctype`, `libxml`.
- MariaDB 10.4+ ou MySQL 5.7+/8.x (InnoDB, utf8mb4).
- Composer 2 (apenas na máquina de desenvolvimento; produção recebe `vendor/` pronto).

## Desenvolvimento local

```bash
composer install
cp .env.example .env                       # preencha DB_* (nunca versione o .env)
php bin/console key:generate --write       # gera APP_KEY no .env
php bin/console migrate
php bin/console app:check                  # diagnóstico sem exibir segredos
php -S 127.0.0.1:8000 -t public public/index.php
```

Windows/XAMPP: `powershell -ExecutionPolicy Bypass -File scripts/dev/test-db.ps1 start` cria um MariaDB isolado em `127.0.0.1:3310` (bancos `concursei_test` e `concursei_dev`) e gera `.env.testing`.

## Comandos

| Comando | Descrição |
| --- | --- |
| `composer test` | Suíte completa (unit + integração + segurança) |
| `composer test:unit` / `test:integration` / `test:security` | Suítes separadas |
| `composer lint` | Sintaxe de todos os arquivos PHP |
| `php scripts/check-repository-safety.php --staged` | Bloqueia segredos/arquivos proibidos antes do commit |
| `php scripts/install-git-hooks.php` | Instala hooks pre-commit/pre-push locais |
| `php bin/console list` | Lista comandos CLI (migrações, manutenção, fila) |

## Estrutura

```text
app/Core/        núcleo: HTTP, roteamento, sessão, segurança, banco, views, console
app/Modules/     módulos de domínio (registrados em app/modules.php)
bootstrap/       montagem da aplicação
config/          padrões públicos (segredos só no .env privado)
database/        migrações SQL versionadas e seeds sintéticos
public/          ÚNICO diretório exposto à web (index.php, assets)
resources/views  templates PHP (escape obrigatório com e()/e_url()/e_json())
storage/         runtime privado (logs, cache, sessões exportadas, uploads) — fora do Git
tests/           Unit, Integration (MariaDB real), Security
docs/            arquitetura, decisões, segurança, deploy, operação
```

## Documentação

- [Decisões de arquitetura](docs/DECISIONS.md)
- [Repositório público e fluxo Git seguro](docs/GIT-PUBLIC-REPOSITORY.md)
- [Progresso](docs/PROGRESS.md) · [Requisitos](docs/REQUIREMENTS.md) · [Relatório de testes](docs/TEST-REPORT.md)

## Segurança

Não abra issues públicas com detalhes de vulnerabilidades. Nunca inclua chaves, senhas ou dados pessoais em commits, issues ou logs.
