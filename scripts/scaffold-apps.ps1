$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Push-Location $projectRoot
try {
    if (-not (Test-Path 'backend')) {
        composer create-project laravel/laravel backend '12.*' --no-interaction --no-scripts
        if ($LASTEXITCODE -ne 0) { throw 'Backend scaffold failed. Inspect partial files; do not auto-delete them.' }
    } else {
        Write-Host 'backend exists; skipped. Inspect composer.json/artisan before continuing.'
    }
    if (-not (Test-Path 'frontend')) {
        npx.cmd --yes sv@1.3.0 create frontend --template minimal --types ts --no-add-ons --no-install
        if ($LASTEXITCODE -ne 0) { throw 'Frontend scaffold failed. Inspect engine/network errors without --force.' }
    } else {
        Write-Host 'frontend exists; skipped. Inspect package.json before continuing.'
    }
    Write-Host 'Scaffold finished/skipped without framework hooks or migrations. Inspect dependency engines and configure .env, Sanctum, MySQL and tests next. Frontend requires compatible Node; use scripts/frontend.ps1 after setup.'
} finally {
    Pop-Location
}

