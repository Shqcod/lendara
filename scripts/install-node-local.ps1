$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolsPath = Join-Path $projectRoot '.tools'
New-Item -ItemType Directory -Path $toolsPath -Force | Out-Null
$releases = Invoke-RestMethod -Uri 'https://nodejs.org/dist/index.json'
$release = $releases |
    Where-Object { $_.version -match '^v22\.' } | Select-Object -First 1
if (-not $release) { throw 'Rilis Node 22 tidak ditemukan.' }
$version = $release.version
$folder = Join-Path $toolsPath ('node-' + $version + '-win-x64')
if (Test-Path -LiteralPath $folder) {
    Write-Host 'Runtime lokal versi ini sudah ada; dipertahankan.'
    exit 0
}
$archive = 'node-' + $version + '-win-x64.zip'
$archivePath = Join-Path $toolsPath $archive
Invoke-WebRequest -UseBasicParsing -Uri ('https://nodejs.org/dist/' + $version + '/' + $archive) -OutFile $archivePath
$checksums = (Invoke-WebRequest -UseBasicParsing -Uri ('https://nodejs.org/dist/' + $version + '/SHASUMS256.txt')).Content
$entry = $checksums -split "`n" | Where-Object { $_ -match ('\s' + [regex]::Escape($archive) + '\s*$') }
if (@($entry).Count -ne 1) { throw 'Entri checksum Node tidak ditemukan atau ambigu.' }
$expected = ($entry -split '\s+')[0]
if ((Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expected) {
    throw 'Checksum Node tidak cocok; runtime tidak diekstrak.'
}
Expand-Archive -LiteralPath $archivePath -DestinationPath $toolsPath
Write-Host ('Node ' + $version + ' lokal terverifikasi SHA256. Node sistem tetap.')
