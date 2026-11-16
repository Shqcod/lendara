param(
    [Parameter(Mandatory=$true)][string]$RestoreId,
    [ValidateSet('Check','Integrity','Accounts','Model','Backend','Frontend','Worker','Scheduler','BrowserProbe','BrowserWorker')][string]$Action = 'Check',
    [ValidatePattern('\Arestore-smoke-[a-f0-9]{32}\z')][string]$QueueName = ''
)
$ErrorActionPreference = 'Stop'
if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) { throw 'Profil restore ini memerlukan Windows DPAPI.' }
if ($RestoreId -notmatch '\A[a-fA-F0-9-]{36}\z') { throw 'RestoreId tidak valid.' }
$projectRoot = Split-Path -Parent $PSScriptRoot
$workspace = Join-Path $projectRoot ('.tools/local-restores/' + $RestoreId)
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_DATABASE = 'kkp_pinjaman_restore_test'
$env:DB_URL = ''
$env:LENDARA_RESTORE_ID = $RestoreId
$env:APP_URL = 'http://127.0.0.1:8800'
$env:FRONTEND_URL = 'http://127.0.0.1:5174'
$env:SANCTUM_STATEFUL_DOMAINS = '127.0.0.1:5174,127.0.0.1:8800'
$env:SESSION_COOKIE = 'lendara_restore_session'
$env:SESSION_DOMAIN = ''
$env:SESSION_SECURE_COOKIE = 'false'
$env:ML_MODE = 'model'
$env:ML_SERVICE_URL = 'http://127.0.0.1:8801'
$env:CACHE_STORE = 'database'
$env:SESSION_DRIVER = 'database'
$env:QUEUE_CONNECTION = 'database'
foreach ($name in @('SESSION_CONNECTION','DB_QUEUE_CONNECTION','DB_CACHE_CONNECTION','DB_CACHE_LOCK_CONNECTION')) { [Environment]::SetEnvironmentVariable($name,'mysql','Process') }
try { $credential = Import-Clixml -LiteralPath (Join-Path $projectRoot '.tools/local-restore-runtime.credential.xml') } catch { throw 'Credential restore tidak tersedia; tidak memakai credential development.' }
if ($credential -isnot [System.Management.Automation.PSCredential] -or $credential.UserName -notmatch '\Alendara_recover_[a-f0-9]{12}\z') { throw 'Credential target restore tidak valid.' }
$env:DB_USERNAME = $credential.UserName
$env:DB_PASSWORD = $credential.GetNetworkCredential().Password
$credential = $null
Push-Location (Join-Path $projectRoot 'backend')
try {
    & php artisan system:assert-runtime-database --restore
    if ($LASTEXITCODE -ne 0) { throw 'DB runtime restore tidak terverifikasi.' }
    & php artisan system:assert-restore-workspace
    if ($LASTEXITCODE -ne 0) { throw 'Workspace/URL/cookie restore tidak terisolasi.' }
    switch ($Action) {
        'Model' { & (Join-Path $PSScriptRoot 'start-local-model.ps1') -RegistrySha256 'd0a0de0d02838762ca1bbef501b32e93d56ecd2d067c7f1d0b23926404fe789c' -RegistryPath (Join-Path $workspace 'artifact-root/docs/validation/ml-runtime-registry-v1.json') -ArtifactRoot (Join-Path $workspace 'artifact-root') -Port 8801 }
        'Integrity' { & php artisan documents:audit-integrity }
        'Accounts' { & php artisan system:local-demo-accounts }
        'BrowserProbe' {
            if ($env:RESTORE_BROWSER_TEST -ne '1') { throw 'Probe hanya untuk runner browser restore.' }
            & php (Join-Path $PSScriptRoot 'php/restore-browser.php')
        }
        'Frontend' {
            # Node only needs the public API URL; do not pass backend credentials to it.
            foreach ($name in @('DB_USERNAME','DB_PASSWORD','DB_URL','APP_KEY','ML_INTERNAL_API_KEY','INTERNAL_API_KEY')) { [Environment]::SetEnvironmentVariable($name,$null,'Process') }
            $env:PUBLIC_API_BASE_URL = 'http://127.0.0.1:8800'
            & (Join-Path $PSScriptRoot 'frontend.ps1') -NpmArgs @('run','dev','--','--config','vite.restore.config.ts')
        }
        default {
            & php artisan system:local-demo-readiness
            if ($LASTEXITCODE -ne 0) { throw 'Runtime model restore belum siap.' }
            switch ($Action) {
                'Backend' { & (Join-Path $PSScriptRoot 'start-backend.ps1') -Port 8800 }
                'Worker' { & php artisan queue:work database --queue=predictions,document-cleanup,default --tries=3 --timeout=0 }
                'Scheduler' { & php artisan schedule:work }
                'BrowserWorker' {
                    if ($env:RESTORE_BROWSER_TEST -ne '1' -or -not $QueueName) { throw 'Worker browser memerlukan antrean fixture khusus.' }
                    & php artisan queue:work database --queue=$QueueName --stop-when-empty --max-jobs=1 --max-time=45 --tries=1 --timeout=0
                }
            }
        }
    }
    $result = $LASTEXITCODE
} finally { Pop-Location }
exit $result
