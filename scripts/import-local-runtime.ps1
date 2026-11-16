$ErrorActionPreference = 'Stop'
if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) { throw 'Credential runtime memerlukan Windows DPAPI.' }
$credentialPath = Join-Path (Split-Path -Parent $PSScriptRoot) '.tools/local-runtime.credential.xml'
if (-not (Test-Path -LiteralPath $credentialPath)) { throw 'Provisioning runtime lokal belum dilakukan; tidak memakai fallback operator.' }
try { $runtimeCredential = Import-Clixml -LiteralPath $credentialPath } catch { throw 'Credential runtime tidak dapat didekripsi oleh akun Windows ini.' }
if ($runtimeCredential -isnot [System.Management.Automation.PSCredential] -or $runtimeCredential.UserName -notmatch '\Alendara_runtime_[a-f0-9]{12}\z') { throw 'Format credential runtime tidak valid.' }
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_DATABASE = 'kkp_pinjaman'
$env:DB_USERNAME = $runtimeCredential.UserName
$env:DB_PASSWORD = $runtimeCredential.GetNetworkCredential().Password
$env:DB_URL = ''
$runtimeCredential = $null
