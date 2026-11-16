param([ValidateSet('Check','Accounts','Integrity','CreateAdmin','CreateReviewer','RegisterModel','Backend','Worker','Scheduler')][string]$Action = 'Check')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$env:APP_ENV = 'local'
$env:ML_MODE = 'model'
$env:ML_SERVICE_URL = 'http://127.0.0.1:8001'
$env:CACHE_STORE = 'database'
$env:QUEUE_CONNECTION = 'database'
$env:SESSION_DRIVER = 'database'
$env:SESSION_CONNECTION = 'mysql'
$env:DB_QUEUE_CONNECTION = 'mysql'
$env:DB_CACHE_CONNECTION = 'mysql'
$env:DB_CACHE_LOCK_CONNECTION = 'mysql'
Push-Location (Join-Path $projectRoot 'backend')
try {
    if ($Action -ne 'RegisterModel') {
        . (Join-Path $PSScriptRoot 'import-local-runtime.ps1')
        & php artisan system:assert-runtime-database
        if ($LASTEXITCODE -ne 0) { throw 'Runtime tidak terverifikasi; tidak memakai operator sebagai fallback.' }
    }
    & php artisan system:assert-local-demo-database
    if ($LASTEXITCODE -ne 0) { throw 'Database profil lokal tidak aman.' }
    if ($Action -eq 'Accounts') {
        & php artisan system:local-demo-accounts
    } elseif ($Action -eq 'Integrity') {
        & php artisan documents:audit-integrity
    } elseif ($Action -eq 'CreateAdmin') {
        & php artisan users:create-admin
    } elseif ($Action -eq 'CreateReviewer') {
        & php artisan users:create-reviewer
    } elseif ($Action -eq 'RegisterModel') {
        $lockPath = Join-Path $projectRoot 'ml-service/experiments/LOCK.json'
        $expected = & git rev-parse 'ml-config-v1-selected:ml-service/experiments/LOCK.json'
        if ($LASTEXITCODE -ne 0) { throw 'Checkpoint selection tidak tersedia.' }
        $actual = & git hash-object --no-filters $lockPath
        if ($LASTEXITCODE -ne 0 -or $actual -ne $expected) { throw 'LOCK berbeda dari checkpoint selection.' }
        $lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json
        & php artisan ml:register-model (Join-Path $projectRoot $lock.selection.metadata_path) ('--sha256=' + $lock.selection.metadata_sha256) --activate
        if ($LASTEXITCODE -ne 0) { throw 'Registrasi/aktivasi ditolak; tidak mengganti model aktif lain.' }
    } else {
        & php artisan system:local-demo-readiness
        if ($LASTEXITCODE -ne 0) { throw 'Preflight belum lulus. Periksa runtime/model sebelum membuka demo.' }
        switch ($Action) {
            'Backend' { & (Join-Path $PSScriptRoot 'start-backend.ps1') }
            'Worker' { & php artisan queue:work database --queue=predictions,document-cleanup,default --tries=3 --timeout=0 }
            'Scheduler' { & php artisan schedule:work }
        }
    }
    $result = $LASTEXITCODE
} finally { Pop-Location }
exit $result
