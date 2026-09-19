$ErrorActionPreference = 'Stop'
$cmsRoot = [IO.Path]::GetFullPath($PSScriptRoot)
foreach ($relative in @('bootstrap/cache','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','storage/app/private')) {
    New-Item -ItemType Directory -Path (Join-Path $cmsRoot $relative) -Force | Out-Null
}
$envPath = Join-Path $cmsRoot '.env'
if (Test-Path -LiteralPath $envPath) {
    Write-Output 'Existing backend/.env preserved. Runtime directories ready.'
    exit 0
}
$template = [IO.File]::ReadAllText((Join-Path $cmsRoot '.env.example'))
$template = $template.Replace('DB_PASSWORD=', 'DB_PASSWORD=' + [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)))
$template = $template.Replace('DB_ROOT_PASSWORD=', 'DB_ROOT_PASSWORD=' + [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)))
$template = $template.Replace('APP_KEY=', 'APP_KEY=base64:' + [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)))
[IO.File]::WriteAllText($envPath, $template, [Text.UTF8Encoding]::new($false))
Write-Output 'Private backend/.env initialized with random local secrets; no values printed.'
