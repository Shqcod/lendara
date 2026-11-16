param(
    [Parameter(Mandatory=$true)][string]$RegistrySha256,
    [string]$RegistryPath = '',
    [string]$PythonExecutable = '',
    [ValidateRange(1024,65535)][int]$Port = 8001,
    [string]$ArtifactRoot = ''
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
if ($RegistrySha256 -notmatch '\A[a-fA-F0-9]{64}\z') { throw 'SHA256 registry tepercaya wajib 64 digit hex.' }
if (-not $RegistryPath) { $RegistryPath = Join-Path $projectRoot 'docs/validation/ml-runtime-registry-v1.json' }
$registry = (Resolve-Path -LiteralPath $RegistryPath).Path
if ((Get-FileHash -LiteralPath $registry -Algorithm SHA256).Hash -ne $RegistrySha256) { throw 'Hash registry tidak cocok; layanan tidak dimulai.' }
if (-not $PythonExecutable) { $PythonExecutable = Join-Path $projectRoot '.tools/ml-training-venv/Scripts/python.exe' }
$python = (Resolve-Path -LiteralPath $PythonExecutable).Path
if (-not $env:INTERNAL_API_KEY) {
    $keyFile = Join-Path $projectRoot 'ml-service/.env'
    if (-not (Test-Path -LiteralPath $keyFile)) { throw 'Kunci internal lokal belum tersedia.' }
    $keyLines = @(Get-Content -LiteralPath $keyFile | Where-Object { $_ -match '^INTERNAL_API_KEY=' })
    if ($keyLines.Count -ne 1) { throw 'Konfigurasi kunci lokal tidak valid.' }
    $env:INTERNAL_API_KEY = ($keyLines[0] -split '=',2)[1].Trim().Trim('"').Trim("'")
}
if (-not $env:INTERNAL_API_KEY) { throw 'Kunci internal lokal kosong.' }
$env:ML_ENV = 'local'
$env:ML_MODE = 'model'
$env:ML_MODEL_REGISTRY = $registry
$env:ML_MODEL_REGISTRY_SHA256 = $RegistrySha256.ToLowerInvariant()
if (-not $ArtifactRoot) { $ArtifactRoot = $projectRoot }
$env:ML_ARTIFACT_ROOT = (Resolve-Path -LiteralPath $ArtifactRoot).Path
Push-Location (Join-Path $projectRoot 'ml-service')
try {
    & $python -m uvicorn app.serve:app --host 127.0.0.1 --port $Port
    $result = $LASTEXITCODE
} finally { Pop-Location }
exit $result
