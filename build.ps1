$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$upload = Join-Path $root 'upload'
$build = Join-Path $root 'build'
$zip = Join-Path $build 'nova_poshta_premium.ocmod.zip'

if (-not (Test-Path $upload)) { throw "upload/ not found at $upload" }
if (-not (Test-Path $build)) { New-Item -ItemType Directory -Path $build | Out-Null }
if (Test-Path $zip) { Remove-Item $zip -Force }

Push-Location $upload
try {
  $items = Get-ChildItem -Force | Where-Object { $_.Name -ne '.DS_Store' }
  Compress-Archive -Path ($items.FullName) -DestinationPath $zip -Force
} finally {
  Pop-Location
}

$info = Get-Item $zip
Write-Output "Built: $($info.FullName) ($([math]::Round($info.Length/1KB,1)) KB)"
