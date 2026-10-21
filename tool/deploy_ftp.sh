#!/usr/bin/env bash
# Puts the committed code on catalog-api.bensds.com over FTPS, sending only
# the files that changed in git since the version that is live:
#
#   mise run deploy                    upload what changed since the live version
#   mise run deploy -- --dry-run       show what would go up (and be removed)
#   mise run deploy -- --all           upload every deployable file
#   mise run deploy -- --since <rev>   compare with <rev> instead of the live version
#   mise run deploy -- --force         deploy even if HEAD doesn't contain the live version
#
# Only what is COMMITTED goes up (HEAD, through `git archive`): uncommitted
# edits are listed and left out. The host keeps the commit it runs in
# `.deploy-rev` (refused to visitors by .htaccess), so whoever deploys next,
# from Windows or Linux, compares with the same version. With none there yet
# (the first deploy), it compares with the repository's first commit, the
# snapshot of the live folder (2026-10-18).
#
# Safe by design:
# - never sent: .env*, .git*, *.md, mise.toml, tool/, build/, any tests/ folder,
#   uploads/photos/; nothing on the host is ever deleted except files deleted
#   in git since the live version (old scripts), and never those above nor vendor/;
# - a folder's .htaccess goes first (it shuts the folder before its PHP files
#   arrive), index.php, admin_*.php and the root .htaccess last (they include
#   the rest): a cut upload never leaves an entry point calling a missing file;
# - each file goes up under a temporary name and replaces the old one once
#   complete; the first failure stops everything and `.deploy-rev` keeps the
#   old version, so running it again sends everything again;
# - the host running a commit this clone doesn't have (someone deployed
#   without you pulling) stops it: git pull first.
# Then it checks that the API answers and says what to do on the host
# (new .env keys, tables, reclassifying the rayons).
#
# The connection comes from the environment (e.g. ~/.bashrc), never from this
# repository; each falls back on bens_ds's (same cPanel account):
#   CATALOG_FTP_HOST      the FTP server              (else BENS_FTP_HOST)
#   CATALOG_FTP_USER      the FTP account             (else BENS_FTP_USER)
#   CATALOG_FTP_PASS_CMD  prints its password, e.g. `pass show bensds/ftp`
#                                                     (else BENS_FTP_PASS_CMD)
#   CATALOG_FTP_DIR       the API's folder as that account sees it
#                         (default caissedz/catalog-api.bensds.com)
#   CATALOG_URL           the API's address (default https://catalog-api.bensds.com)
# TLS is required for the login and the files; the certificate must check out.
#
# Linux (Arch): sudo pacman -S lftp git curl  (flock is in util-linux).
# Windows: mise run depwin (tool/deploy_win.sh) runs this in WSL.
set -euo pipefail
cd "$(dirname "$0")/.."

dry="" all=0 since="" force=0
while [ $# -gt 0 ]; do
  case $1 in
    --dry-run) dry=1 ;;
    --all) all=1 ;;
    --force) force=1 ;;
    --since) since="${2:?--since needs a commit}"; shift ;;
    --since=*) since="${1#--since=}" ;;
    *)
      echo "Unknown: $1 (use --dry-run, --all, --since <rev>, --force)" >&2
      exit 64
      ;;
  esac
  shift
done

die() { echo "✗ $*" >&2; exit 1; }
# The checkout may sit on a Windows drive (WSL): no ownership check.
g() { git -c safe.directory='*' "$@"; }

host="${CATALOG_FTP_HOST:-${BENS_FTP_HOST:-}}"
user="${CATALOG_FTP_USER:-${BENS_FTP_USER:-}}"
pass_cmd="${CATALOG_FTP_PASS_CMD:-${BENS_FTP_PASS_CMD:-}}"
dir="${CATALOG_FTP_DIR:-caissedz/catalog-api.bensds.com}"
dir="${dir%/}"
url="${CATALOG_URL:-https://catalog-api.bensds.com}"
for t in git curl flock; do command -v $t >/dev/null || die "$t is needed"; done

g rev-parse --verify -q HEAD >/dev/null || die "Nothing committed yet."
head=$(g rev-parse HEAD)
short() { g rev-parse --short "$1"; }

mkdir -p build
exec 9>build/deploy.lock
flock -n 9 || die "Another deploy is running."

# Never sent (see the top).
deployable() { grep -vE '^\.(env|git)|(^|/)tests/|\.md$|^(mise\.toml|tool/|build/|uploads/photos/)' || true; }

# Uncommitted changes: left out, and said so.
dirty=$(g status --porcelain --untracked-files=all | cut -c4- | sed 's/.* -> //' | deployable)
if [ -n "$dirty" ]; then
  echo "! Not committed, so NOT deployed:"
  sed 's/^/    /' <<<"$dirty"
fi
# Pushed? (Whoever deploys next works from the shared history.)
if upstream=$(g rev-parse -q --verify '@{upstream}' 2>/dev/null); then
  ahead=$(g rev-list --count "$upstream..HEAD")
  [ "$ahead" = 0 ] || echo "! $ahead commit(s) not pushed yet: git push, so the others have what goes live."
fi

# The FTP side: needed unless it's a dry run against a given commit.
ftp_needed=1
[ -n "$dry" ] && [ -n "$since" ] && ftp_needed=0
tls="set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate true
set ftp:passive-mode true
set net:max-retries 12
set net:reconnect-interval-base 2
set net:reconnect-interval-multiplier 1.5
set net:reconnect-interval-max 15
set net:timeout 20
set xfer:use-temp-file yes
set cmd:fail-exit yes
"
if [ $ftp_needed = 1 ]; then
  [ -n "$host" ] || die "Set CATALOG_FTP_HOST (or BENS_FTP_HOST), see tool/deploy_ftp.sh"
  [ -n "$user" ] || die "Set CATALOG_FTP_USER (or BENS_FTP_USER), see tool/deploy_ftp.sh"
  [ -n "$pass_cmd" ] || die "Set CATALOG_FTP_PASS_CMD (or BENS_FTP_PASS_CMD), see tool/deploy_ftp.sh"
  command -v lftp >/dev/null || die "lftp is needed: sudo pacman -S lftp (Ubuntu/WSL: sudo apt install lftp)"
  password="$(sh -c "$pass_cmd")" || true
  [ -n "$password" ] || die "No password from: $pass_cmd"
fi
# lftp, logged in, running [commands] after the TLS settings.
ftp() { LFTP_PASSWORD="$password" lftp --env-password -u "$user" "$host" <<<"$tls$1"; }

live=""
if [ $ftp_needed = 1 ]; then
  # The folder must be the API's (a wrong CATALOG_FTP_DIR would make a new one).
  listing=$(ftp "cls -1a $dir/" 2>&1) || {
    echo "✗ Couldn't list '$dir' on $host:" >&2
    echo "$listing" >&2
    exit 1
  }
  grep -qE '(^|/)index\.php$' <<<"$listing" ||
    die "No index.php in '$dir' on $host: set CATALOG_FTP_DIR to the API's folder (it has: $(tr '\n' ' ' <<<"$listing"))"
  # Read only if it's there: this host retries a missing file for minutes.
  if grep -qE '(^|/)\.deploy-rev$' <<<"$listing"; then
    live=$(ftp "cat $dir/.deploy-rev" 2>/dev/null | tr -d '[:space:]') || live=""
    [ -n "$live" ] || die "Couldn't read $dir/.deploy-rev on $host: run it again."
  fi
fi

first=0
if [ -n "$since" ]; then
  base=$(g rev-parse --verify -q "$since^{commit}") || die "Unknown commit: $since"
elif [ -n "$live" ]; then
  g cat-file -e "$live^{commit}" 2>/dev/null ||
    die "The host runs $live, which this clone doesn't have: git pull first."
  base=$live
else
  base=$(g rev-list --max-parents=0 HEAD | tail -n 1)
  first=1
fi
if [ $force = 0 ] && ! g merge-base --is-ancestor "$base" HEAD; then
  die "HEAD ($(short HEAD)) doesn't contain what is live ($(short "$base")): git pull / merge first (or --force)."
fi

echo "→ $url: $(short "$base") → $(short HEAD)$([ $first = 1 ] && echo ' (first deploy: compared with the first commit, the live snapshot)')"

if [ $all = 1 ]; then
  mapfile -t send < <(g ls-tree -r --name-only HEAD | deployable)
else
  mapfile -t send < <(g diff --name-only --no-renames --diff-filter=ACMRT "$base" HEAD | deployable)
fi
# (Never vendor/: its libraries may be on the host without being in git.)
mapfile -t removed < <(g diff --name-only --no-renames --diff-filter=D "$base" HEAD | deployable | grep -v '^vendor/' || true)

# Order: folders' .htaccess, then the rest, then the entry points.
entry() { [[ $1 == index.php || $1 == .htaccess || $1 =~ ^admin_[^/]*\.php$ ]]; }
ordered=()
for f in "${send[@]}"; do [[ $f == */.htaccess ]] && ordered+=("$f"); done
for f in "${send[@]}"; do [[ $f == */.htaccess ]] || entry "$f" || ordered+=("$f"); done
for f in "${send[@]}"; do entry "$f" && ordered+=("$f"); done

if [ ${#ordered[@]} = 0 ] && [ ${#removed[@]} = 0 ]; then
  echo "  up to date: nothing to send."
  # Only non-deployed files changed (README…): the host now runs HEAD all the same.
  if [ -z "$dry" ] && [ $ftp_needed = 1 ] && [ "$live" != "$head" ]; then
    printf '%s\n' "$head" >build/deploy-rev
    ftp "put build/deploy-rev -o \"$dir/.deploy-rev\"" >/dev/null && echo "  live: $(short HEAD)"
  fi
  exit 0
fi
echo "  ${#ordered[@]} file(s) to send, ${#removed[@]} to remove${dry:+ (dry run: nothing changes)}"
for f in "${ordered[@]}"; do echo "    ↑ $f"; done
for f in "${removed[@]}"; do echo "    ✗ $f (deleted in git)"; done

# What to do on the host after this version.
notes=()
newkeys=$(g diff "$base" HEAD -- .env.example | sed -nE 's/^\+([A-Z_][A-Z0-9_]*)=.*/\1/p' | tr '\n' ' ')
[ -z "$newkeys" ] || notes+=("New .env keys (add them to the host's .env): $newkeys")
if printf '%s\n' "${ordered[@]}" | grep -qE '(^|/)[A-Za-z]*Schema\.php$'; then
  notes+=("Tables changed: open $url/admin_rayons.php and $url/admin_smartscan.php → « Installer / mettre à jour les tables ».")
fi
if printf '%s\n' "${ordered[@]}" | grep -qE '^catalogue_core/Rayons\.php$'; then
  notes+=("Sorting rules changed: $url/admin_rayons.php → « Reclasser tout le catalogue » (or php catalogue_core/reclasser.php).")
fi

if [ -n "$dry" ]; then
  for n in "${notes[@]}"; do echo "! $n"; done
  echo "Done (dry run)."
  exit 0
fi

# The committed files, exactly as in HEAD.
stage=build/deploy-stage
rm -rf "$stage"
mkdir -p "$stage"
[ ${#ordered[@]} = 0 ] || g archive --format=tar HEAD -- "${ordered[@]}" | tar -x -C "$stage"
printf '%s\n' "$head" >build/deploy-rev

script=""
made=""
for f in "${ordered[@]}"; do
  d=$(dirname "$f")
  if [ "$d" != . ] && ! grep -qxF "$d" <<<"$made"; then
    script+="mkdir -p -f \"$dir/$d\""$'\n'
    made+="$d"$'\n'
  fi
  script+="echo \"  ↑ $f\""$'\n'"put \"$stage/$f\" -o \"$dir/$f\""$'\n'
done
for f in "${removed[@]}"; do
  script+="echo \"  ✗ $f\""$'\n'"rm -f \"$dir/$f\""$'\n'
done
# Last: the version now live.
script+="put build/deploy-rev -o \"$dir/.deploy-rev\""$'\n'

start=$SECONDS
if ! ftp "$script"; then
  die "Something didn't go up (above). Run it again: the live version wasn't changed, so it all goes again."
fi
echo "Done: ${#ordered[@]} file(s) sent, ${#removed[@]} removed in $((SECONDS - start)) s; live: $(short HEAD)."

for n in "${notes[@]}"; do echo "! $n"; done
echo "→ Checking"
body=$(curl -s --max-time 20 "$url/") || body=""
grep -q 'Connect' <<<"$body" || die "Uploaded, but $url/ answers: ${body:0:300}"
echo "  ✓ the API answers"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$url/rayons") || code=000
if [ "$code" = 200 ]; then
  echo "  ✓ /rayons"
else
  # Normal right after the first deploy: the tables install when admin_rayons.php opens.
  echo "! $url/rayons answers $code: open $url/admin_rayons.php (installs the tables), then reclassify."
fi
