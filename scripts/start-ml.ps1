$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$servicePath = Join-Path $projectRoot 'ml-service'
$pythonPath = Join-Path $servicePath '.venv\Scripts\python.exe'
if (-not (Test-Path $pythonPath)) { throw 'Create ml-service/.venv with Python3.12 and install requirements.txt first (README).' }
$envPath = Join-Path $servicePath '.env'
if (-not (Test-Path $envPath)) {
    $apiKey = & $pythonPath -c 'import secrets; print(secrets.token_urlsafe(32))'
    if ($LASTEXITCODE -ne 0) { throw 'Could not generate internal API key.' }
    @('ML_ENV=local', 'ML_MODE=stub', ('INTERNAL_API_KEY=' + $apiKey.Trim()), 'ML_STUB_TIMEOUT_SECONDS=25') | Set-Content -LiteralPath $envPath -Encoding utf8
    Write-Host 'Created ml-service/.env with a random local key. Keep it private; match it to Laravel ML_INTERNAL_API_KEY locally.'
}
$allowed = @('ML_ENV','ML_MODE','INTERNAL_API_KEY','ML_STUB_TIMEOUT_SECONDS')
foreach ($line in Get-Content -LiteralPath $envPath) {
    $trimmed = $line.Trim()
    if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }
    $parts = $trimmed -split '=', 2
    if ($parts.Length -ne 2 -or $allowed -notcontains $parts[0].Trim()) { throw 'Unexpected .env entry. This helper supports only the four documented ML settings.' }
    [Environment]::SetEnvironmentVariable($parts[0].Trim(), $parts[1].Trim(), 'Process')
}
Push-Location $servicePath
try {
    & $pythonPath -m uvicorn app.main:app --host 127.0.0.1 --port 8001
    if ($LASTEXITCODE -ne 0) { throw 'FastAPI exited with an error.' }
} finally { Pop-Location }

