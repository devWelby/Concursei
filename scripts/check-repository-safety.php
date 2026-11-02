<?php

declare(strict_types=1);

/**
 * Verificador de segurança do repositório público Concursei.
 *
 * Uso:
 *   php scripts/check-repository-safety.php            # = --staged
 *   php scripts/check-repository-safety.php --staged   # conteúdo em staging (pre-commit)
 *   php scripts/check-repository-safety.php --tracked  # todos os arquivos rastreados
 *   php scripts/check-repository-safety.php --history  # todo o histórico (antes do 1º push)
 *   php scripts/check-repository-safety.php --history=origin/main..HEAD
 *
 * Falha (exit 1) quando:
 *   - arquivo proibido está rastreado ou em staging (segredos, dumps, runtime, vendor...);
 *   - conteúdo contém padrão de segredo/credencial ou e-mail fora de domínios reservados;
 *   - arquivo essencial (composer.lock, migrações) passou a ser ignorado;
 *   - gitleaks (se instalado) acusa vazamento.
 *
 * Os achados NUNCA imprimem o valor do segredo: apenas arquivo, linha e tipo.
 * Exceção revisada por linha: incluir o marcador "repo-safety: allow" na mesma linha.
 * Este script é auxiliar; não substitui a revisão humana do diff.
 */

const MAX_FILE_BYTES = 5 * 1024 * 1024;
const ALLOW_MARKER = 'repo-safety: allow';

/** Caminhos proibidos de estar no índice (regex sobre caminho com "/"). */
const FORBIDDEN_PATHS = [
    'arquivo .env real' => '#(^|/)\.env($|\.(?!example$)[^/]+$)#',
    'chave/certificado privado' => '#\.(pem|key|p8|p12|pfx|jks|keystore|ppk)$#i',
    'chave SSH' => '#(^|/)id_(rsa|dsa|ecdsa|ed25519)(\.pub)?$#',
    'credencial de serviço' => '#(^|/)(credentials[^/]*|client_secret[^/]*|service-account[^/]*|[^/]*-service-account)\.json$#i',
    'credencial Composer/npm' => '#(^|/)(auth\.json|\.npmrc|\.pypirc|\.netrc|\.htpasswd)$#',
    'dependências instaladas' => '#^(vendor|node_modules)/#',
    'runtime em storage/' => '#^storage/(?!(.+/)?\.gitkeep$).+#',
    'dump/backup de banco' => '#(\.sql\.(gz|zip|bz2)|\.dump|\.bak|\.backup|\.sqlite3?|\.db)$|(^|/)(dump-[^/]*|[^/]*[-_]dump|backup-[^/]*|[^/]*-backup)\.sql$|^(backups?|database/(dumps|backups))/#i',
    'log' => '#\.log$#i',
    'pacote compactado' => '#\.(zip|tar|tgz|7z|rar)$|\.tar\.gz$#i',
    'configuração pessoal de IA/MCP' => '#^(\.claude/|\.mcp\.json$|\.cursor/|\.continue/|\.codeium/|\.windsurf/|graphify-out/)#',
    'configuração local' => '#^config/(local\.php|.+\.local\.[^/]+|secrets(/|\.php$)|production\.php)#',
];

/** Arquivos que precisam continuar versionáveis. */
const ESSENTIAL_NOT_IGNORED = [
    'composer.json', 'composer.lock', '.env.example', '.gitignore',
    'database/migrations/0001_example.sql', 'public/index.php', 'public/.htaccess',
    'storage/logs/.gitkeep', 'tests/Unit/ExampleTest.php',
];

/** Padrões de conteúdo sensível. Nome => regex. */
const SECRET_PATTERNS = [
    'bloco de chave privada' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP |ENCRYPTED )?PRIVATE KEY-----/',
    'chave de API Google/Gemini' => '/AIza[0-9A-Za-z_\-]{35}/',
    'token OAuth Google' => '/ya29\.[0-9A-Za-z_\-]{20,}/',
    'chave AWS' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
    'token GitHub' => '/\b(?:gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{40,})\b/',
    'token Slack' => '/\bxox[abposr]-[A-Za-z0-9-]{10,}/',
    'chave Stripe' => '/\b(?:sk|rk)_live_[A-Za-z0-9]{16,}/',
    'token WhatsApp/Meta' => '/\bEAA[A-Za-z0-9]{40,}/',
    'JWT' => '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
    'URL com credencial' => '#\b[a-z][a-z0-9+.-]*://[^\s:/@"\']+:[^\s:/@"\']{3,}@[^\s"\']+#i',
    'chave SendGrid' => '/\bSG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}/',
];

/** Atribuições genéricas de segredo: KEY=valor / "key": "valor". */
const ASSIGNMENT_PATTERN = '/(?<name>[A-Za-z0-9_.-]*(?:pass(?:word|wd)?|secret|api[_-]?key|token|private[_-]?key|client[_-]?secret|credential)[A-Za-z0-9_.-]*)["\']?\s*(?:=|:|=>)\s*["\']?(?<value>[^\s"\',;)]{8,})/i';

/** Valores que são claramente placeholders/expressões, não segredos. */
const PLACEHOLDER_HINTS = [
    'change', 'example', 'placeholder', 'your', 'seu', 'sua', 'xxxx', '****', 'dummy', 'fake', 'redacted',
    'redigido', 'troque', 'defina', 'not-set', 'none', 'null', 'false', 'true', 'test', 'local-only',
];

const RESERVED_EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net', 'localhost', 'concursei.test'];
const RESERVED_EMAIL_TLDS = ['test', 'invalid', 'example', 'localhost'];

/** Arquivos cujo conteúdo é sabidamente seguro e geraria ruído (lockfile com hashes). */
const CONTENT_SCAN_SKIP = ['#^composer\.lock$#', '#\.(png|jpe?g|gif|webp|ico|woff2?|pdf)$#i'];

// ---------------------------------------------------------------------

function git(array $args, ?int &$exitCode = null): string
{
    $cmd = array_merge(['git'], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Não foi possível executar git.\n");
        exit(2);
    }
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    return (string) $out;
}

/** @return list<string> */
function nulList(string $raw): array
{
    return array_values(array_filter(explode("\0", $raw), static fn (string $s): bool => $s !== ''));
}

function isPlaceholder(string $value): bool
{
    $v = strtolower($value);
    if (str_starts_with($v, '$') || str_starts_with($v, '%') || str_starts_with($v, '<') || str_starts_with($v, '{')
        || str_contains($v, '(') || str_contains($v, '->') || str_contains($v, '::') || str_contains($v, '[')) {
        return true; // variável, chamada ou expressão de código
    }
    foreach (PLACEHOLDER_HINTS as $hint) {
        if (str_contains($v, $hint)) {
            return true;
        }
    }
    // Identificadores de código (ex.: password_hash, $token, csrf_token) não são segredos.
    return (bool) preg_match('/^[a-z_][a-z0-9_.]*$/', $v) && !preg_match('/\d/', $v);
}

function emailIsReserved(string $email): bool
{
    $domain = strtolower(substr($email, strrpos($email, '@') + 1));
    if (in_array($domain, RESERVED_EMAIL_DOMAINS, true)) {
        return true;
    }
    foreach (RESERVED_EMAIL_DOMAINS as $d) {
        if (str_ends_with($domain, '.' . $d)) {
            return true;
        }
    }
    $tld = substr($domain, strrpos($domain, '.') + 1);
    return in_array($tld, RESERVED_EMAIL_TLDS, true);
}

/**
 * @return list<array{line:int,type:string}>
 */
function scanContent(string $content): array
{
    $findings = [];
    if (str_contains($content, "\0")) {
        return $findings; // binário
    }
    $lines = preg_split('/\R/', $content) ?: [];
    foreach ($lines as $i => $line) {
        if (str_contains($line, ALLOW_MARKER)) {
            continue;
        }
        foreach (SECRET_PATTERNS as $type => $re) {
            if (preg_match($re, $line)) {
                $findings[] = ['line' => $i + 1, 'type' => $type];
            }
        }
        if (preg_match_all(ASSIGNMENT_PATTERN, $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                if (!isPlaceholder($match['value'])) {
                    $findings[] = ['line' => $i + 1, 'type' => 'atribuição de segredo em "' . $match['name'] . '"'];
                }
            }
        }
        if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $line, $em)) {
            foreach ($em[0] as $email) {
                if (!emailIsReserved($email)) {
                    $findings[] = ['line' => $i + 1, 'type' => 'e-mail real (use domínio reservado, ex.: example.com)'];
                }
            }
        }
    }
    return $findings;
}

function skipContent(string $path): bool
{
    foreach (CONTENT_SCAN_SKIP as $re) {
        if (preg_match($re, $path)) {
            return true;
        }
    }
    return false;
}

/** @return list<string> */
function checkPaths(array $paths, string $origin): array
{
    $problems = [];
    foreach ($paths as $p) {
        $p = str_replace('\\', '/', $p);
        foreach (FORBIDDEN_PATHS as $label => $re) {
            if (preg_match($re, $p)) {
                $problems[] = "[$origin] arquivo proibido ($label): $p";
            }
        }
    }
    return $problems;
}

// ---------------------------------------------------------------------

$mode = 'staged';
$range = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--staged' || $arg === '--tracked') {
        $mode = substr($arg, 2);
    } elseif ($arg === '--history') {
        $mode = 'history';
    } elseif (str_starts_with($arg, '--history=')) {
        $mode = 'history';
        $range = substr($arg, 10);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Uso: php scripts/check-repository-safety.php [--staged|--tracked|--history[=intervalo]]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Argumento desconhecido: $arg\n");
        exit(2);
    }
}

git(['rev-parse', '--show-toplevel'], $code);
if ($code !== 0) {
    fwrite(STDERR, "Execute dentro do repositório Git.\n");
    exit(2);
}
chdir(trim(git(['rev-parse', '--show-toplevel'])));

$problems = [];

// 1) Arquivos rastreados/staged proibidos (sempre verificado).
$tracked = nulList(git(['ls-files', '-z']));
$staged = nulList(git(['diff', '--cached', '--name-only', '-z', '--diff-filter=ACMR']));
$problems = array_merge($problems, checkPaths($tracked, 'rastreado'), checkPaths($staged, 'staging'));

// Rastreado mas coberto pelo .gitignore = foi adicionado indevidamente ou regra mudou.
$trackedIgnored = nulList(git(array_merge(['ls-files', '-z', '--cached', '-i', '--exclude-standard'])));
foreach ($trackedIgnored as $p) {
    $problems[] = "[rastreado] arquivo coberto pelo .gitignore continua no índice: $p (use git rm --cached)";
}

// 2) Essenciais não podem ser ignorados.
foreach (ESSENTIAL_NOT_IGNORED as $p) {
    $out = trim(git(['check-ignore', '--no-index', '-v', $p]));
    if ($out !== '') {
        [$source] = explode("\t", $out);
        if (!str_contains($source, ':!')) {
            $problems[] = "[ignore] arquivo essencial seria ignorado: $p ($source)";
        }
    }
}

// 3) Varredura de conteúdo.
$scanned = 0;
if ($mode === 'staged' || $mode === 'tracked') {
    $files = $mode === 'staged' ? $staged : $tracked;
    foreach ($files as $path) {
        if (skipContent($path)) {
            continue;
        }
        $content = $mode === 'staged' ? git(['show', ':' . $path]) : (is_file($path) ? (string) file_get_contents($path) : '');
        if (strlen($content) > MAX_FILE_BYTES) {
            $problems[] = "[$mode] arquivo grande (> 5 MB), revise se deve ser versionado: $path";
            continue;
        }
        $scanned++;
        foreach (scanContent($content) as $f) {
            $problems[] = "[$mode] $path:{$f['line']} possível {$f['type']}";
        }
    }
} else {
    $logArgs = ['log', '-p', '--no-color', '--no-ext-diff', '-U0', '--format=commit %H'];
    $logArgs[] = $range ?? '--all';
    $currentCommit = '';
    $currentFile = '';
    $lineNo = 0;
    foreach (preg_split('/\R/', git($logArgs)) ?: [] as $line) {
        if (str_starts_with($line, 'commit ')) {
            $currentCommit = substr($line, 7, 10);
            $scanned++;
        } elseif (str_starts_with($line, '+++ b/')) {
            $currentFile = substr($line, 6);
            foreach (checkPaths([$currentFile], 'histórico ' . $currentCommit) as $p) {
                $problems[] = $p;
            }
        } elseif (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $line, $hm)) {
            $lineNo = (int) $hm[1];
        } elseif (str_starts_with($line, '+') && !str_starts_with($line, '+++') && !skipContent($currentFile)) {
            foreach (scanContent(substr($line, 1)) as $f) {
                $problems[] = "[histórico $currentCommit] $currentFile:$lineNo possível {$f['type']}";
            }
            $lineNo++;
        }
    }
}

// 4) gitleaks opcional (complementar).
$gitleaksCode = null;
$where = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
@exec($where . ' gitleaks 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $o, $gitleaksCode);
if ($gitleaksCode === 0) {
    $glArgs = $mode === 'staged' ? 'protect --staged --redact --no-banner' : 'detect --redact --no-banner' . ($range ? ' --log-opts=' . escapeshellarg($range) : '');
    passthru('gitleaks ' . $glArgs, $glExit);
    if ($glExit !== 0) {
        $problems[] = '[gitleaks] vazamento potencial detectado (veja saída acima, valores redigidos)';
    }
} else {
    echo "aviso: gitleaks não encontrado no PATH; usando somente as regras internas.\n";
}

$problems = array_values(array_unique($problems));
if ($problems !== []) {
    fwrite(STDERR, "\nVerificação de segurança do repositório FALHOU (" . count($problems) . " achado(s)):\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  - $p\n");
    }
    fwrite(STDERR, "\nNão faça commit/push. Corrija a causa; se for falso positivo revisado, use o marcador '" . ALLOW_MARKER . "' na linha.\n");
    fwrite(STDERR, "Se um segredo real já foi commitado/publicado: revogue e rotacione a credencial (veja docs/GIT-PUBLIC-REPOSITORY.md).\n");
    exit(1);
}

echo "Verificação de segurança do repositório OK (modo: $mode, itens verificados: $scanned, rastreados: " . count($tracked) . ").\n";
exit(0);
