#!/bin/sh
# Pictures of every screen, in both themes and at both widths, into a folder for looking at.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/tests"
. "$ROOT/tools/site.sh"
OUT=${1:-/tmp/audit}
mkdir -p "$OUT"
SID=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
CSRF=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
COOKIE=$($SSH "$HOST" "cd ~/$REMOTE_APP && OWNER=\$($PHP -r 'require \"lib/bootstrap.php\"; echo q(\"SELECT CONCAT(id, \\\";epoch|i:\\\", session_epoch) FROM users WHERE role = \\\"owner\\\"\")->fetchColumn();') \
  && printf 'user_id|i:%s;csrf|s:32:\"%s\";' \$OWNER $CSRF > sessions/sess_$SID && chmod 600 sessions/sess_$SID \
  && $PHP -r 'require \"lib/bootstrap.php\"; echo config(\"session_name\");'")
# Opening chapters as a real member rearranges their saved place and Recent list, and opening the
# settings can change what they read with. Copy it first, put it back however this ends.
STATE=data/reader-state-$SID.json
$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/reader_state.php save $STATE" >/dev/null
trap '$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/reader_state.php restore $STATE; rm -f $STATE sessions/sess_$SID"' EXIT
export SITE_URL COOKIE
node design_audit.js "$SID" "$OUT"
echo "pictures in $OUT"
ls "$OUT" | wc -l
