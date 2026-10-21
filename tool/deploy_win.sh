#!/usr/bin/env bash
# `mise run deploy` from Windows (Git Bash), same options:
#
#   mise run depwin                    upload what changed since the live version
#   mise run depwin -- --dry-run       show what would go up
#   mise run depwin -- --all           upload every deployable file
#
# tool/deploy_ftp.sh runs unchanged in WSL (Ubuntu: sudo apt install lftp git curl),
# on this same checkout under C:.
#
# The connection comes from Windows' environment variables (System settings →
# Environment Variables, or `setx CATALOG_FTP_HOST …`), never from this
# repository: CATALOG_FTP_HOST, CATALOG_FTP_USER, CATALOG_FTP_DIR, CATALOG_URL
# (see tool/deploy_ftp.sh), each falling back on bens_ds's BENS_FTP_* (same
# cPanel account). The password: CATALOG_FTP_PASS_CMD (or BENS_FTP_PASS_CMD),
# run here in Git Bash, or else the one saved for this Windows user by
# `mise run depwin-pass` (%USERPROFILE%\.bens\catalog-ftp-pass), or else
# bens_ds's (%USERPROFILE%\.bens\ftp-pass).
set -euo pipefail
cd "$(dirname "$0")/.."

command -v wsl.exe >/dev/null || {
  echo "✗ WSL is needed (PowerShell as administrator: wsl --install)" >&2
  exit 1
}
missing=$(wsl.exe -e sh -c 'for t in lftp git flock curl; do command -v $t >/dev/null || printf "%s " $t; done') || {
  echo "✗ WSL doesn't start (wsl --install, or wsl --status)" >&2
  exit 1
}
[ -z "$missing" ] || {
  echo "✗ Missing in WSL: $missing(in Ubuntu: sudo apt install ${missing% })" >&2
  exit 1
}

: "${CATALOG_FTP_HOST:=${BENS_FTP_HOST:-}}"
: "${CATALOG_FTP_USER:=${BENS_FTP_USER:-}}"
[ -n "$CATALOG_FTP_HOST" ] || { echo "✗ Set CATALOG_FTP_HOST in Windows (see tool/deploy_win.sh)" >&2; exit 1; }
[ -n "$CATALOG_FTP_USER" ] || { echo "✗ Set CATALOG_FTP_USER in Windows (see tool/deploy_win.sh)" >&2; exit 1; }
export CATALOG_FTP_HOST CATALOG_FTP_USER

pass_cmd="${CATALOG_FTP_PASS_CMD:-${BENS_FTP_PASS_CMD:-}}"
pass_file="$USERPROFILE/.bens/catalog-ftp-pass"
[ -f "$pass_file" ] || pass_file="$USERPROFILE/.bens/ftp-pass"
if [ -n "$pass_cmd" ]; then
  CATALOG_FTP_PASSWORD="$(sh -c "$pass_cmd")" || true
elif [ -f "$pass_file" ]; then
  CATALOG_FTP_PASSWORD="$(powershell.exe -NoProfile -NonInteractive -Command \
    "[Net.NetworkCredential]::new('', (Get-Content '$(cygpath -w "$pass_file")' | ConvertTo-SecureString)).Password" |
    tr -d '\r')" || true
else
  echo "✗ No password: mise run depwin-pass (or set CATALOG_FTP_PASS_CMD)" >&2
  exit 1
fi
[ -n "$CATALOG_FTP_PASSWORD" ] || {
  echo "✗ No password from ${pass_cmd:-$pass_file}" >&2
  exit 1
}
export CATALOG_FTP_PASSWORD
export CATALOG_FTP_PASS_CMD='printf %s "$CATALOG_FTP_PASSWORD"'

# What WSL takes from here.
pass=CATALOG_FTP_PASSWORD:CATALOG_FTP_PASS_CMD:CATALOG_FTP_HOST:CATALOG_FTP_USER:CATALOG_FTP_DIR:CATALOG_URL
export WSLENV="$pass${WSLENV:+:$WSLENV}"
exec wsl.exe -e bash tool/deploy_ftp.sh "$@"
