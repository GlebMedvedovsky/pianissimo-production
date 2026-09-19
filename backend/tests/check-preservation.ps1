$ErrorActionPreference='Stop'
$projectRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$baselinePath=Join-Path $projectRoot 'docs/qa/03A/before/protected-hashes.json'
$baseline=Get-Content -LiteralPath $baselinePath -Raw | ConvertFrom-Json
$changed=@()
foreach($entry in $baseline) {
    if(-not (Test-Path -LiteralPath $entry.path)) { $changed+=$entry.path; continue }
    if((Get-FileHash -LiteralPath $entry.path -Algorithm SHA256).Hash -ne $entry.sha256) { $changed+=$entry.path }
}
$currentFiles=foreach($relative in @('frontend/src','frontend/public','frontend/dist','contracts','content','frontend/astro.config.mjs','package.json','package-lock.json')) {
    Get-ChildItem -LiteralPath (Join-Path $projectRoot $relative) -Recurse -File | ForEach-Object {$_.FullName}
}
$extra=@($currentFiles | Where-Object {$_ -notin $baseline.path})
$result=@{protectedFileCount=$baseline.Count;changed=$changed;added=$extra}
$result | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $projectRoot 'docs/qa/03A/preservation.json') -Encoding utf8
if($changed.Count -or $extra.Count) { throw 'Protected frontend/contracts/content files changed; inspect the private report.' }
Write-Output "All $($baseline.Count) protected file hashes match; no files added in protected paths."
