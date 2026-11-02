# Requisitos e rastreabilidade

Origem: prompt técnico do Concursei (seções indicadas como §N) e análise da documentação de produto. Status: **feito** (implementado e testado), **parcial**, **pendente**, **bloqueado** (depende de credencial/decisão externa).

## Fundação, Git e operação

| ID | Requisito | Origem | Prio | Status | Implementação | Teste |
| --- | --- | --- | --- | --- | --- | --- |
| G01 | `.gitignore` validado antes do 1º commit | §3.2 | P0 | feito | `.gitignore` | `git check-ignore`/`git add --dry-run` (TEST-REPORT) |
| G02 | Verificador de segredos/arquivos proibidos + hooks | §3.2 | P0 | feito | `scripts/check-repository-safety.php`, `scripts/install-git-hooks.php` | iscas sintéticas |
| G03 | CI com lint, testes e detector de segredos | §3.2, §17 | P0 | pendente | — | — |
| F01 | Front controller, rotas explícitas, sem include arbitrário | §6 | P0 | feito | `public/index.php`, `app/Core/Http/Router.php` | `HttpTest`, `KernelTest` |
| F02 | Somente `public/` exposto; layout `public_html` | §6 | P0 | parcial | resolução de app root, `.htaccess`, `BootGuard` | `KernelTest`; deploy real pendente |
| F03 | Erros padronizados com request ID, sem stack trace | §17, §20 | P0 | feito | `App::renderException` | `KernelTest` |
| F04 | Migrações serial com versão/checksum | §7 | P0 | feito | `Migrator` | `InfrastructureTest` |
| F05 | Falha segura de inicialização em produção | §5 | P0 | feito | `BootGuard`, `public/index.php` | unitário pendente |
| F06 | Logs redigidos | §17 | P0 | feito | `Logger` | `SecurityPrimitivesTest` |
| F07 | Fila SQL com lease, retries e Cron CLI | §16 | P0 | pendente | — | — |
| F08 | Backup/restauração documentados e testados | §17, §21 | P0 | pendente | — | — |

## Segurança transversal

| ID | Requisito | Origem | Prio | Status | Implementação | Teste |
| --- | --- | --- | --- | --- | --- | --- |
| S01 | CSRF em toda mutação (inclui AJAX/logout) + Origin | §17 | P0 | feito | `VerifyCsrf` | integração com rotas de auth (pendente) |
| S02 | Sessão endurecida, logout real, expiração | §8 | P0 | feito | `Session`, `DatabaseSessionHandler` | `InfrastructureTest` |
| S03 | Rate limit persistente e atômico | §17 | P0 | feito | `RateLimiter`, `Throttle` | `InfrastructureTest` |
| S04 | Headers (CSP nonce, nosniff, frame, referrer, HSTS opt-in) | §17 | P0 | feito | `SecurityHeaders` | `SecurityPrimitivesTest`, `KernelTest` |
| S05 | Escape contextual e redirect local | §17 | P0 | feito | `helpers.php`, `Response::redirect` | `HttpTest`, `SecurityPrimitivesTest` |
| S06 | SSRF no coletor (IPv4/IPv6/DNS/redirect) | §17 | P0 | pendente | — | — |
| S07 | Upload restrito fora do webroot | §17 | P0 | pendente | — | — |
| S08 | ASVS L2 checklist + modelo de ameaça | §17 | P0 | pendente | — | — |

## Produto P0

| ID | Requisito | Origem | Status |
| --- | --- | --- | --- |
| A01 | Cadastro, login, confirmação de e-mail, reset, perfil | §8 | **feito** — `tests/Integration/Auth/{Registration,Login,PasswordReset,Account}Test.php` (SMTP real pendente de credencial) |
| A02 | Papéis e policies de propriedade (IDOR) | §8 | parcial — papéis/permissões e bloqueio de `/admin` testados (`LoginTest`); policies de propriedade virão com favoritos, anotações e simulados |
| A03 | MFA TOTP obrigatório para administradores | §8 | **feito** — `tests/Integration/Auth/MfaTest.php`, `LoginTest::testAdminAreaRequiresPermissionAndStaffMfa` |
| C01 | Catálogo/calendário com filtros, paginação, datas desconhecidas | §9, §21 | pendente |
| C02 | Edital, fonte, evidência, revisão, retificação auditáveis | §10, §21 | pendente |
| C03 | Geração de simulados via Gemini com limites | §11, §12 | pendente |
| C04 | Correção reprodutível e conclusão idempotente | §12 | pendente |
| C05 | Mapa de erros com amostra e "sem dados" | §13 | pendente |
| C06 | Planejador sem exceder disponibilidade; PDF/ICS | §13 | pendente |
| C07 | Conta, painel, favoritos, anotações, isolamento | §8 | pendente |
| C08 | Alertas por e-mail com opt-in, outbox, descadastro | §15 | pendente |
| C10 | Hospedagem: layout privado, roteiro cPanel/Cron | §5, §16 | parcial |
| C11 | Falhas externas sem falso sucesso | §11 | pendente |
| C12 | Commits seguros, nenhum segredo versionado | §3 | feito (contínuo) |
| M01 | SEO (canonical, sitemap, noindex privado) | §19 | parcial (noindex/canonical no layout) |
| M02 | Slots de anúncio desativados até config/consentimento | §19 | pendente |
| M03 | Privacidade, cookies, termos, exportação/exclusão de conta | §18 | pendente |

## P1 / P2

| ID | Requisito | Prio | Status |
| --- | --- | --- | --- |
| C09 | Nota de corte, salário líquido, comparador, perfil, históricos | P1 | pendente |
| P2-1 | WhatsApp Business (API oficial) | P2 | fora do escopo atual |
| P2-2 | Google Calendar OAuth (ICS já cobre importação) | P2 | fora do escopo atual |
