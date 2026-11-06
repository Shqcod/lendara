$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'start-backend.ps1') -Testing
exit $LASTEXITCODE
