$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.yaml'
if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.env'))) { throw 'Run backend/Initialize-Local.ps1 and the first-install instructions first.' }
$probe = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 8080)
$occupied = $false
try { $probe.Start() } catch [Net.Sockets.SocketException] { $occupied = $true } finally { $probe.Stop() }
if ($occupied) {
    $webJson = & docker compose -f $composeFile ps --format json web
    if ($LASTEXITCODE -ne 0) { throw 'Cannot establish the owner of port 8080; no processes were stopped.' }
    $web = $webJson | ConvertFrom-Json
    $ours = $web | Where-Object { $_.State -eq 'running' -and ($_.Publishers | Where-Object { $_.URL -eq '127.0.0.1' -and $_.PublishedPort -eq 8080 }) }
    if (-not $ours) {
        Get-NetTCPConnection -State Listen -LocalPort 8080 -ErrorAction SilentlyContinue | Select-Object LocalAddress,LocalPort,OwningProcess
        throw 'Port 8080 is occupied by another process. It was not stopped; no alternative port was chosen.'
    }
}
& docker compose -f $composeFile up -d
if ($LASTEXITCODE -ne 0) { throw 'CMS startup failed.' }
Write-Output 'Local CMS: http://127.0.0.1:8080/admin (do not forward this port). Frontend 4321 is independent.'
