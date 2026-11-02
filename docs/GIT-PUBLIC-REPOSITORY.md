# Repositório público — fluxo Git seguro

O repositório **Concursei** é público. Tudo que entra no histórico deve ser tratado como publicado para sempre.

## 1. Regras

- Um commit por alteração lógica concluída (pequena ou grande). Arquivos que precisam mudar juntos ficam no mesmo commit; trabalhos independentes, não.
- Staging explícito por caminho (`git add caminho`) ou por hunk. **Não usar `git add .` nem `git add -A`.**
- Nunca reescrever histórico, `reset --hard`, descartar alterações de terceiros nem fazer force push sem autorização específica.
- Commit local não é push. Push somente com autorização e depois de revisar **todos** os commits a enviar.
- Mensagens no formato `tipo(escopo): descrição` — `feat`, `fix`, `docs`, `test`, `chore`, `refactor`, `perf`, `security`.

## 2. Checklist de cada commit

```bash
git status --short                      # separar o que é seu do que é preexistente
git diff                                # revisar o conteúdo
# verificações proporcionais (lint/testes) — ver README
git add caminho/arquivo1 caminho/arquivo2
git diff --cached                       # revisar o que realmente vai entrar
git diff --cached --check               # espaços/conflitos
php scripts/check-repository-safety.php --staged
git commit -m "tipo(escopo): descrição"
git log --oneline -1                    # registrar o hash na entrega
```

Hooks locais opcionais (auxiliares, não substituem revisão):

```bash
php scripts/install-git-hooks.php       # pre-commit (--staged) e pre-push (--history do intervalo enviado)
```

## 3. O que o verificador cobre

`php scripts/check-repository-safety.php [--staged|--tracked|--history[=intervalo]]`

| Verificação | Detalhe |
| --- | --- |
| Arquivos proibidos rastreados/em staging | `.env` reais, chaves (`.pem`, `.key`, `.p12`, `.pfx`...), credenciais JSON, `auth.json`, `vendor/`, runtime em `storage/`, dumps/backups, logs, pacotes compactados, configuração pessoal de IA/MCP, `config/local.php` |
| Rastreado mas ignorado | Arquivo que o `.gitignore` cobre e continua no índice (`.gitignore` não remove arquivos já rastreados) |
| Essenciais | `composer.lock`, migrações, `.env.example`, `public/index.php` etc. não podem passar a ser ignorados |
| Conteúdo | Blocos de chave privada, chaves Google/Gemini, AWS, GitHub, Slack, Stripe, Meta/WhatsApp, SendGrid, JWT, URL com credencial, atribuições `*PASSWORD/SECRET/TOKEN/API_KEY=valor`, e-mails fora de domínios reservados (`example.com`, `.test`, `.invalid`) |
| gitleaks | Executado adicionalmente quando instalado no PATH (`--redact`) |

O relatório mostra arquivo, linha e tipo — **nunca o valor**. Falso positivo revisado: acrescentar `repo-safety: allow` na própria linha e justificar no commit.

Validação das regras de ignore (exemplos de cada categoria e essenciais):

```bash
git check-ignore -v --no-index .env storage/logs/app.log backups/x.sql   # devem ser ignorados
git add --dry-run .env.example storage composer.lock                    # devem ser adicionáveis
```

Atenção: `git check-ignore -v` também imprime padrões de negação (`!padrão`); nesse caso o arquivo **não** é ignorado.

## 4. Antes do primeiro push (e de cada push)

```bash
git remote -v                                            # confirmar origin = devWelby/Concursei
git log --oneline origin/main..HEAD                      # (ou todo o log no primeiro push)
php scripts/check-repository-safety.php --history        # todo o histórico a publicar
php scripts/check-repository-safety.php --tracked
```

## 5. Resposta a vazamento

Se um segredo real foi commitado (mesmo que só localmente) ou publicado:

1. **Não repetir o valor** em mensagens, issues, logs ou relatórios. Referir-se a ele por tipo e local (ex.: "chave Gemini em `config/x.php`, commit `abc1234`").
2. Interromper qualquer push pendente.
3. **Revogar e rotacionar** a credencial no provedor imediatamente. Apagar no commit seguinte não remove do histórico; se já foi publicado, considere comprometido.
4. Se ainda não houve push: corrigir o commit local com orientação explícita do responsável. Se houve push: limpeza de histórico (ex.: `git filter-repo`) exige coordenação e autorização explícitas, e não dispensa a rotação.
5. Registrar o incidente (sem o valor) e a ação tomada.

## 6. Materiais locais fora do Git

Os arquivos de planejamento recebidos (prompt, análise e guia em PDF de terceiros) ficam apenas na pasta local, excluídos via `.git/info/exclude`. Versioná-los exige decisão explícita do responsável; material de terceiros exige direito de redistribuição.
