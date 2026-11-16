$ErrorActionPreference = 'Stop'
if (-not $env:RUNTIME_DB_USERNAME -or -not $env:RUNTIME_DB_PASSWORD) { throw 'Kredensial runtime test tidak tersedia.' }
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = $env:RUNTIME_DB_PORT
$env:DB_DATABASE = 'kkp_pinjaman_test'
$env:DB_USERNAME = $env:RUNTIME_DB_USERNAME
$env:DB_PASSWORD = $env:RUNTIME_DB_PASSWORD
$env:DB_URL = ''
$env:CACHE_STORE = 'database'
$env:SESSION_CONNECTION = 'mysql'
$env:DB_QUEUE_CONNECTION = 'mysql'
Push-Location (Join-Path (Split-Path -Parent $PSScriptRoot) 'backend')
try {
    & php tests/Support/runtime-context.php
    if ($LASTEXITCODE -ne 0) { throw 'Identitas runtime DB tidak terbukti.' }
} finally { Pop-Location }
& (Join-Path $PSScriptRoot 'start-backend.ps1') -Testing
exit $LASTEXITCODE
