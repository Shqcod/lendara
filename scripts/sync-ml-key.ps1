$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$mlEnv = Join-Path $projectRoot 'ml-service/.env'
if (-not (Test-Path -LiteralPath $mlEnv)) { throw 'Jalankan start-ml.ps1 terlebih dahulu agar kunci lokal tersedia.' }
$line = Get-Content -LiteralPath $mlEnv | Where-Object { $_ -match '^INTERNAL_API_KEY=\S+$' }
if (@($line).Count -ne 1) { throw 'Konfigurasi kunci ML tidak valid. Periksa .env secara lokal.' }
$key = ($line -split '=', 2)[1]
foreach ($name in @('.env', '.env.testing')) {
    $path = Join-Path $projectRoot ('backend/' + $name)
    if (-not (Test-Path -LiteralPath $path)) { throw 'Siapkan konfigurasi lokal backend terlebih dahulu.' }
    $content = [System.IO.File]::ReadAllText($path)
    if ($content -notmatch '(?m)^ML_INTERNAL_API_KEY=\s*$') {
        Write-Host ($name + ': kunci sudah terisi; dipertahankan. Cocokkan secara lokal jika berbeda.')
        continue
    }
    $updated = [regex]::Replace($content, '(?m)^ML_INTERNAL_API_KEY=[ \t]*\r?$', ('ML_INTERNAL_API_KEY=' + $key))
    [System.IO.File]::WriteAllText($path, $updated, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host ($name + ': kunci kosong diisi dari layanan ML lokal; nilainya tidak ditampilkan.')
}
