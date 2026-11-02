# Progresso

Atualizado em 2026-11-02. Situação real — nada aqui é declarado pronto sem teste correspondente em [TEST-REPORT.md](TEST-REPORT.md).

## Concluído

1. **Proteção do repositório** — `.gitignore` validado por categoria, `.gitattributes`, verificador de segredos com hooks pre-commit/pre-push, documentação do fluxo público.
2. **Fundação do núcleo** — configuração/ambiente, contêiner, HTTP (Request/Response/Router), validação pt-BR, views com escape, banco PDO estrito, migrações com checksum, logger redigido, criptografia (AES-GCM/HMAC/HKDF), sessão em banco, CSRF, rate limit, headers com CSP nonce, kernel com erros padronizados, front controller, console CLI, BootGuard, layout acessível.

## Em andamento / próximos passos (ordem)

1. Autenticação completa (cadastro, confirmação de e-mail, login, logout, reset, perfil, reautenticação) + papéis/policies + MFA TOTP de administrador + criação de admin por CLI.
2. E-mail: adaptador SMTP (biblioteca mantida) + outbox + transporte de captura para testes.
3. Catálogo vertical: órgãos, bancas, cargos, concursos/edições/ofertas/eventos → publicação revisada → listagem/filtros → detalhe → favorito → painel.
4. CI (GitHub Actions) com lint, testes em MySQL, verificador de segredos.
5. Fila SQL + Cron; coletor seguro (SSRF); adaptador Gemini; revisão de mudanças.
6. Simulados, correção, contestação; mapa de estudo; planejador; alertas; CMS/SEO; P1.

## Pendências externas

- Credenciais Gemini e SMTP; dados reais de concursos e fontes autorizadas.
- Confirmar na conta HostGator: versão PHP web/CLI, extensões, versão do banco, caminho do PHP CLI, frequência do Cron, raiz do domínio.
- Identidade do operador (nome/contato) para privacidade e termos.
- Autorização para `git push` ao remoto `origin` (github.com/devWelby/Concursei) — ainda não houve push.
- Strix (pentest) requer Docker + chave de LLM.

## Como retomar

```bash
powershell -ExecutionPolicy Bypass -File scripts/dev/test-db.ps1 start   # Windows: banco isolado
composer install
vendor/bin/phpunit
php bin/console migrate:status
git log --oneline | head -20
```

Arquivos locais fora do Git (por decisão D-009): `Prompt-Claude-Code-Concursei.md`, `Analise-da-documentacao.md`, guia em PDF.
