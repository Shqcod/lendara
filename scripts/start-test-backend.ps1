param([switch]$PrepareStubModel)
$ErrorActionPreference = 'Stop'
if ($PrepareStubModel) {
    $projectRoot = Split-Path -Parent $PSScriptRoot
    Push-Location (Join-Path $projectRoot 'backend')
    try {
        & php artisan system:assert-test-database --env=testing
        if ($LASTEXITCODE -ne 0) { throw 'Database test tidak aman atau belum siap.' }
        & php artisan ml:register-stub --env=testing --activate
        if ($LASTEXITCODE -ne 0) { throw 'Fixture model stub tidak siap; model aktif lain tidak diganti.' }
    } finally { Pop-Location }
}
& (Join-Path $PSScriptRoot 'start-backend.ps1') -Testing
exit $LASTEXITCODE
