# Saves the FTP password for `mise run depwin` (tool/deploy_win.sh), encrypted
# with DPAPI: only this Windows user, on this machine, can read it back.
#
#   mise run depwin-pass
$ErrorActionPreference = 'Stop'
$dir = Join-Path $env:USERPROFILE '.bens'
New-Item -ItemType Directory -Force $dir | Out-Null
$secure = Read-Host 'FTP password (CATALOG_FTP_USER)' -AsSecureString
if ($secure.Length -eq 0) { Write-Error 'No password given: nothing saved.' }
$secure | ConvertFrom-SecureString | Set-Content -Encoding ascii (Join-Path $dir 'catalog-ftp-pass')
Write-Host "Saved in $dir\catalog-ftp-pass"
