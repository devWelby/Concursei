# Instância MariaDB isolada para desenvolvimento/testes do Concursei (somente Windows/XAMPP).
# Não usa nem altera o data dir do XAMPP. Dados e credenciais ficam FORA do repositório,
# em %LOCALAPPDATA%\Concursei. Escuta apenas em 127.0.0.1.
#
# Uso:
#   powershell -ExecutionPolicy Bypass -File scripts/dev/test-db.ps1 start
#   powershell -ExecutionPolicy Bypass -File scripts/dev/test-db.ps1 status
#   powershell -ExecutionPolicy Bypass -File scripts/dev/test-db.ps1 stop
#
# Variáveis opcionais: CONCURSEI_MYSQL_BIN (padrão C:\xampp\mysql\bin), CONCURSEI_DEVDB_PORT (padrão 3310).

param([ValidateSet('start', 'stop', 'status')][string]$Action = 'status')

$ErrorActionPreference = 'Stop'
$bin = if ($env:CONCURSEI_MYSQL_BIN) { $env:CONCURSEI_MYSQL_BIN } else { 'C:\xampp\mysql\bin' }
$port = if ($env:CONCURSEI_DEVDB_PORT) { [int]$env:CONCURSEI_DEVDB_PORT } else { 3310 }
$base = Join-Path $env:LOCALAPPDATA 'Concursei'
$data = Join-Path $base 'devdb'
$credFile = Join-Path $base 'devdb.credentials'
$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$envTesting = Join-Path $repoRoot '.env.testing'

function New-Secret([int]$bytes = 24) {
    $b = New-Object byte[] $bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    return ([Convert]::ToBase64String($b) -replace '[+/=]', '')
}

function Get-Listening { netstat -ano | Select-String "127.0.0.1:$port\s+.*LISTENING" }

function Invoke-Sql([string]$sql, [string]$rootPass) {
    & (Join-Path $bin 'mysql.exe') --protocol=TCP -h 127.0.0.1 -P $port -u root "--password=$rootPass" -e $sql
    if ($LASTEXITCODE -ne 0) { throw "Falha ao executar SQL de preparação." }
}

switch ($Action) {
    'status' {
        if (Get-Listening) { "Ativo em 127.0.0.1:$port (dados: $data)" } else { "Parado (dados: $data)" }
    }
    'stop' {
        if (-not (Test-Path $credFile)) { 'Sem credenciais; nada a parar.'; break }
        $root = (Get-Content $credFile | Where-Object { $_ -like 'ROOT=*' }) -replace '^ROOT=', ''
        & (Join-Path $bin 'mysqladmin.exe') --protocol=TCP -h 127.0.0.1 -P $port -u root "--password=$root" shutdown
        'Instância parada.'
    }
    'start' {
        New-Item -ItemType Directory -Force $base | Out-Null
        $firstRun = -not (Test-Path (Join-Path $data 'mysql'))
        if ($firstRun) {
            $root = New-Secret
            & (Join-Path $bin 'mysql_install_db.exe') "--datadir=$data" "--password=$root" | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'mysql_install_db falhou.' }
            Set-Content -Path $credFile -Value "ROOT=$root" -Encoding ascii
        }
        if (-not (Get-Listening)) {
            Start-Process -FilePath (Join-Path $bin 'mysqld.exe') -WindowStyle Hidden -ArgumentList @(
                "--datadir=`"$data`"", "--port=$port", '--bind-address=127.0.0.1', '--skip-name-resolve',
                '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci', '--default-time-zone=+00:00'
            )
            for ($i = 0; $i -lt 30 -and -not (Get-Listening); $i++) { Start-Sleep -Milliseconds 500 }
            if (-not (Get-Listening)) { throw "mysqld não respondeu na porta $port." }
        }
        $root = (Get-Content $credFile | Where-Object { $_ -like 'ROOT=*' }) -replace '^ROOT=', ''
        $appPass = (Get-Content $credFile | Where-Object { $_ -like 'TEST=*' }) -replace '^TEST=', ''
        if (-not $appPass) {
            $appPass = New-Secret
            Add-Content -Path $credFile -Value "TEST=$appPass" -Encoding ascii
        }
        Invoke-Sql ("CREATE DATABASE IF NOT EXISTS concursei_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
            "CREATE DATABASE IF NOT EXISTS concursei_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
            "CREATE USER IF NOT EXISTS 'concursei'@'127.0.0.1' IDENTIFIED BY '$appPass'; " +
            "ALTER USER 'concursei'@'127.0.0.1' IDENTIFIED BY '$appPass'; " +
            "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON concursei_test.* TO 'concursei'@'127.0.0.1'; " +
            "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON concursei_dev.* TO 'concursei'@'127.0.0.1'; FLUSH PRIVILEGES;") $root
        if (-not (Test-Path $envTesting)) {
            @(
                '# Gerado por scripts/dev/test-db.ps1 — arquivo local, ignorado pelo Git.',
                'APP_ENV=testing',
                'DB_HOST=127.0.0.1', "DB_PORT=$port", 'DB_DATABASE=concursei_test', 'DB_USERNAME=concursei', "DB_PASSWORD=$appPass"
            ) | Set-Content -Path $envTesting -Encoding ascii
            "Criado .env.testing (ignorado pelo Git)."
        }
        "Instância pronta em 127.0.0.1:$port (bancos concursei_test e concursei_dev; usuário de aplicação 'concursei' sem privilégios globais)."
    }
}
