# Progresso

Atualizado em 2026-11-02. Situação real — nada aqui é declarado pronto sem teste correspondente em [TEST-REPORT.md](TEST-REPORT.md).

## Concluído

1. **Proteção do repositório** — `.gitignore` validado por categoria, `.gitattributes`, verificador de segredos com hooks pre-commit/pre-push, documentação do fluxo público.
2. **Fundação do núcleo** — configuração/ambiente, contêiner, HTTP (Request/Response/Router), validação pt-BR, views com escape, banco PDO estrito, migrações com checksum, logger redigido, criptografia (AES-GCM/HMAC/HKDF), sessão em banco, CSRF, rate limit, headers com CSP nonce, kernel com erros padronizados, front controller, console CLI, BootGuard, layout acessível.
3. **E-mail transacional** — outbox no banco (idempotência, reserva com lock, nova tentativa, corpo apagado após envio), SMTP via PHPMailer, captura local, comando `mail:send-pending` (D-011).
4. **Autenticação e conta** — cadastro com aceite versionado de termos/privacidade/idade e marketing opcional; confirmação de e-mail (POST, uso único, 24 h); login com respostas iguais para e-mail inexistente e senha errada; limites por IP e por conta; logout real; recuperação de senha (1 h, uso único, encerra todas as sessões, avisa o titular); perfil e fuso; troca de senha (encerra outras sessões) e de e-mail (confirmação no novo endereço e aviso ao antigo); preferências de marketing revogáveis; MFA TOTP com códigos de recuperação, anti-replay e bloqueio por conta (D-012); papéis/permissões com MFA obrigatório para a equipe em `/admin`; CLI `user:create-admin`, `user:role`, `user:mfa-reset`, `tokens:purge` (D-013).
5. **CI** — GitHub Actions com validação do Composer, auditoria de dependências, lint, verificador de segredos (arquivos rastreados e histórico) e PHPUnit em matriz PHP 8.2/8.3/8.4 × MariaDB 10.4/MySQL 5.7/8.0; ações fixadas por SHA. Simulada localmente; ainda não rodou no GitHub (commit não enviado).
6. **Catálogo vertical** (D-015) — cadastros de órgãos, bancas e cargos; concursos com cargos e datas com precisão; fluxo rascunho → revisão → publicação com conferência humana, nota pública e revisões imutáveis; controle otimista de edição; arquivamento; `/concursos` com filtros e paginação; `/concursos/{slug}` a partir da revisão publicada, com histórico; `/calendario` mensal; acompanhar concurso; `/painel` com contagem regressiva (destino após o login); Cron `catalog:refresh`; `dev:seed-demo` com dados fictícios.

## Em andamento / próximos passos (ordem)

1. Fila SQL + Cron; coletor seguro (SSRF); adaptador Gemini; extração de edital com evidência e revisão de mudanças.
2. Páginas institucionais (privacidade, termos, cookies, sobre, correções) — hoje o rodapé, o cadastro e a página de concurso apontam para elas, mas ainda não existem; exportação e exclusão de conta.
3. Simulados, correção, contestação; mapa de estudo; planejador; alertas por e-mail (usarão os favoritos); CMS/SEO (sitemap, dados estruturados); P1.

## Cron previsto (HostGator)

| Comando | Frequência |
| --- | --- |
| `php bin/console catalog:refresh` | diário, logo após 00h de Brasília |
| `php bin/console mail:send-pending` | a cada 5–15 min (respeitando o limite horário de envio) |
| `php bin/console tokens:purge` | diário |

## Pendências externas

- Credenciais Gemini e SMTP (sem SMTP configurado, cadastro e recuperação de senha ficam indisponíveis em produção, por desenho).
- Dados reais de concursos: o catálogo só tem dados fictícios de demonstração no banco local; o cadastro real depende da equipe conferir cada edital.
- Textos jurídicos de Termos e Privacidade revisados por quem responde pelo site (as versões aceitas no cadastro estão em `config/legal.php`).
- Confirmar na conta HostGator: versão PHP web/CLI, extensões, versão do banco, caminho do PHP CLI, frequência do Cron, raiz do domínio.
- Identidade do operador (nome/contato) para privacidade e termos.
- Autorização para `git push` ao remoto `origin` (github.com/devWelby/Concursei). O remoto está em `c142327`; os commits locais seguintes (CI e catálogo) ainda não foram enviados, e a CI só roda no GitHub depois do envio.
- Strix (pentest) requer Docker + chave de LLM.

## Como retomar

```bash
powershell -ExecutionPolicy Bypass -File scripts/dev/test-db.ps1 start   # Windows: banco isolado
# Pare com "... test-db.ps1 stop" ao terminar: encerrar o mysqld à força corrompe as tabelas
# de sistema (Aria). Se acontecer: mysqlcheck --repair --databases mysql na porta 3310.
composer install
vendor/bin/phpunit
php bin/console migrate:status
composer serve                                   # http://127.0.0.1:8000
php bin/console dev:seed-demo --as=<e-mail da equipe>   # opcional: concursos fictícios
git log --oneline | head -20
```

Arquivos locais fora do Git (por decisão D-009): `Prompt-Claude-Code-Concursei.md`, `Analise-da-documentacao.md`, guia em PDF.
