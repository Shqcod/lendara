param([switch]$Testing, [switch]$Restore, [string]$RestoreId = '')
$ErrorActionPreference = 'Stop'
if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) { throw 'Penyimpanan credential ini hanya untuk Windows DPAPI.' }
$projectRoot = Split-Path -Parent $PSScriptRoot
if ($Testing -and $Restore) { throw 'Testing dan Restore tidak boleh digabung.' }
if ($Restore -and $RestoreId -notmatch '\A[a-fA-F0-9-]{36}\z') { throw 'RestoreId wajib.' }
$name = if ($Restore) { 'local-restore-runtime.credential.xml' } elseif ($Testing) { 'runtime-test-' + [guid]::NewGuid().ToString() + '.credential.xml' } else { 'local-runtime.credential.xml' }
$credentialPath = Join-Path $projectRoot ('.tools/' + $name)
if (Test-Path -LiteralPath $credentialPath) { throw 'Credential sudah ada; tidak ditimpa. Periksa profil yang ada.' }
$env:APP_ENV = if ($Testing) { 'testing' } else { 'local' }
$info = New-Object System.Diagnostics.ProcessStartInfo
$info.FileName = (Get-Command php).Source
$mode = if ($Restore) { '--restore-pipe' } elseif ($Testing) { '--testing-pipe' } else { '--local-pipe' }
if ($Restore) { $env:RESTORE_WORKSPACE_ID = $RestoreId }
$info.Arguments = '"' + (Join-Path $projectRoot 'scripts/php/local-runtime-account.php') + '" ' + $mode
$info.WorkingDirectory = Join-Path $projectRoot 'backend'
$info.UseShellExecute = $false
$info.CreateNoWindow = $true
$info.RedirectStandardInput = $true
$info.RedirectStandardOutput = $true
$info.RedirectStandardError = $true
$broker = [System.Diagnostics.Process]::Start($info)
$acknowledged = $false
try {
    $line = $broker.StandardOutput.ReadLine()
    if (-not $line) { throw 'Broker tidak menghasilkan credential; periksa izin operator/koneksi.' }
    $data = $line | ConvertFrom-Json
    if ([int]$data.port -ne 3306) { throw 'Profil lokal ini hanya untuk port MySQL3306.' }
    $secure = ConvertTo-SecureString -String $data.password -AsPlainText -Force
    $credential = New-Object System.Management.Automation.PSCredential ($data.username, $secure)
    $credential | Export-Clixml -LiteralPath $credentialPath -NoClobber
    if ([IO.File]::ReadAllText($credentialPath).Contains($data.password)) { throw 'Credential tidak terenkripsi.' }
    $loaded = Import-Clixml -LiteralPath $credentialPath
    if ($loaded.GetNetworkCredential().Password -cne $data.password) { throw 'Round-trip DPAPI gagal.' }
    $env:DB_CONNECTION = 'mysql'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = [string]$data.port
    $env:DB_DATABASE = if ($Restore) { 'kkp_pinjaman_restore_test' } elseif ($Testing) { 'kkp_pinjaman_test' } else { 'kkp_pinjaman' }
    $env:DB_USERNAME = $loaded.UserName
    $env:DB_PASSWORD = $loaded.GetNetworkCredential().Password
    $env:DB_URL = ''
    if ($Restore) { $env:LENDARA_RESTORE_ID = $RestoreId }
    Push-Location (Join-Path $projectRoot 'backend')
    try {
        if ($Restore) { & php artisan system:assert-runtime-database --restore } else { & php artisan system:assert-runtime-database }
        if ($LASTEXITCODE -ne 0) { throw 'Grant runtime tidak lulus guard.' }
    } finally { Pop-Location }
    $message = if ($Testing) { 'cleanup' } else { 'persisted' }
    $broker.StandardInput.WriteLine($message)
    $broker.StandardInput.Close()
    if (-not $broker.WaitForExit(30000) -or $broker.ExitCode -ne 0) { throw 'Konfirmasi provisioning gagal; jangan memakai fallback operator.' }
    $acknowledged = $true
    Write-Output 'PASS: credential DPAPI tersimpan dan identitas/grant runtime terverifikasi.'
    if ($Testing) { Write-Output 'PASS: akun test sementara dibersihkan; credential fixture terenkripsi dipertahankan.' }
} catch {
    # Never print exception objects that may contain credential input or private pipe content.
    throw 'Provisioning tidak selesai. Periksa file credential lokal dan akun milik run sebelum mencoba kembali; tidak ada overwrite/fallback.'
} finally {
    if (-not $acknowledged) {
        try { $broker.StandardInput.Close() } catch {}
        if (-not $broker.WaitForExit(30000)) { Write-Warning 'Broker belum berhenti; periksa hanya akun provisioning milik run.' }
    }
    $data = $null; $line = $null; $credential = $null; $loaded = $null; $secure = $null
    $env:DB_PASSWORD = $null
    $broker.Dispose()
}
