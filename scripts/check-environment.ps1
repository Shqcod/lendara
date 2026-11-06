$ErrorActionPreference = 'Stop'
Write-Host 'Node / npm'
node --version
npm.cmd --version
Write-Host 'PHP / config / extensions'
where.exe php
php --version
php --ini
php --ri curl
php --ri zip
php -m
Write-Host 'Composer / Git / Python'
composer --version
git --version
$projectRoot = Split-Path -Parent $PSScriptRoot
$pythonPath = Join-Path $projectRoot 'ml-service/.venv/Scripts/python.exe'
if (-not (Test-Path -LiteralPath $pythonPath)) { $pythonPath = (Get-Command python -ErrorAction Stop).Source }
& $pythonPath --version
if ($LASTEXITCODE -ne 0) { throw 'Python tidak dapat dijalankan. Periksa instalasi Python 3.12.' }
& $pythonPath -m pip --version
if ($LASTEXITCODE -ne 0) { throw 'pip tidak dapat dijalankan.' }
Get-ChildItem -LiteralPath (Join-Path $projectRoot '.tools') -Directory -Filter 'node-v22*-win-x64' -ErrorAction SilentlyContinue | ForEach-Object {
    Write-Host 'Node lokal proyek (dipakai scripts/frontend.ps1)'
    & (Join-Path $_.FullName 'node.exe') --version
}
Write-Host 'MySQL server: run SELECT VERSION() in HeidiSQL. This script does not connect with stored credentials.'
Write-Host 'Then run composer diagnose separately and inspect the full audit output.'

