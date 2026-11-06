param([switch]$Testing)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$previousEnvironment = $env:APP_ENV
$previousCookie = $env:SESSION_COOKIE
if ($Testing) {
    $env:APP_ENV = 'testing'
    $env:SESSION_COOKIE = 'lendara_test_session'
}
Push-Location (Join-Path $projectRoot 'backend')
try {
    if ($Testing) {
        & php artisan system:assert-test-database --env=testing
        if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi database test tidak aman atau belum siap.' }
    }
    $router = Join-Path $projectRoot 'backend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
    Push-Location public
    try {
        # Process-only limits; do not change php.ini or the user's .env.
        & php -d upload_max_filesize=5M -d post_max_size=8M -d display_errors=0 -S 127.0.0.1:8000 $router
        $result = $LASTEXITCODE
    } finally { Pop-Location }
} finally {
    Pop-Location
    $env:APP_ENV = $previousEnvironment
    $env:SESSION_COOKIE = $previousCookie
}
exit $result
