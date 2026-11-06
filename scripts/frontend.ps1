param([Parameter(ValueFromRemainingArguments = $true)][string[]]$NpmArgs)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtime = Get-ChildItem -LiteralPath (Join-Path $projectRoot '.tools') -Directory -Filter 'node-v22*-win-x64' -ErrorAction SilentlyContinue |
    Sort-Object { [version]($_.Name -replace '^node-v|\-win-x64$', '') } -Descending | Select-Object -First 1
$previousPath = $env:Path
if ($runtime) { $env:Path = $runtime.FullName + ';' + $env:Path }
Push-Location (Join-Path $projectRoot 'frontend')
try {
    $nodeVersion = [version]((& node --version).TrimStart('v'))
    if ($nodeVersion.Major -ne 22 -or $nodeVersion -lt [version]'22.17.0') {
        throw 'Frontend memerlukan Node 22.17+ dalam seri 22. Gunakan runtime lokal .tools atau perbarui patch Node 22.'
    }
    if (-not $NpmArgs) { $NpmArgs = @('run', 'dev') }
    & npm.cmd @NpmArgs
    $result = $LASTEXITCODE
} finally {
    Pop-Location
    $env:Path = $previousPath
}
exit $result
