$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$oldEnvironment = $env:APP_ENV
$oldCookie = $env:SESSION_COOKIE
$env:APP_ENV = 'testing'
$env:SESSION_COOKIE = 'lendara_test_session'
Push-Location (Join-Path $projectRoot 'backend')
try {
    & php artisan system:assert-test-database --env=testing
    if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi database test tidak aman atau belum siap.' }
    & php artisan serve --env=testing --host=127.0.0.1 --port=8000 --no-reload
    $result = $LASTEXITCODE
} finally {
    Pop-Location
    $env:APP_ENV = $oldEnvironment
    $env:SESSION_COOKIE = $oldCookie
}
exit $result
