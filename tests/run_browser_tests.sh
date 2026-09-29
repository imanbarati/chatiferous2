#!/bin/sh
# Runs tests/browser_test.js against the live site as the owner, in a topic called "Platform"
# (make one first).
# Creates a temporary sign-in session on the server and removes it afterwards.
# Needs: npm install (in tests/) and Chromium (set CHROMIUM=/path if not /usr/bin/chromium-browser).
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/tests"
. "$ROOT/tools/site.sh"
[ -d node_modules/puppeteer-core ] || npm install --silent

SID=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
CSRF=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')

TOPIC=$($SSH "$HOST" "cd ~/$REMOTE_APP && OWNER=\$($PHP -r 'require \"lib/bootstrap.php\"; echo q(\"SELECT CONCAT(id, \\\";epoch|i:\\\", session_epoch) FROM users WHERE role = \\\"owner\\\"\")->fetchColumn();') \
  && printf 'user_id|i:%s;csrf|s:32:\"%s\";' \$OWNER $CSRF > sessions/sess_$SID && chmod 600 sessions/sess_$SID \
  && $PHP -r 'require \"lib/bootstrap.php\"; echo q(\"SELECT id FROM topics WHERE title = \\\"Platform\\\"\")->fetchColumn(), \" \", config(\"session_name\");'")
# The reader part of the run opens chapters, changes settings and marks verses, and the app
# remembers all of it against whoever did it. So it is not done as the owner: a throwaway member is
# made for it and deleted afterwards, and the owner's reading is never touched. (Restoring a
# snapshot instead would erase whatever the owner read while the run was going.)
RSID=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/test_reader_account.php make $RSID $CSRF" >/dev/null
# On exit, however the run ended (even a crash): end the owner's session, take the throwaway reader
# and all it remembered away with it, and delete any clearly-marked test posts the test missed.
trap '$SSH "$HOST" "cd ~/$REMOTE_APP && rm -f sessions/sess_$SID && $PHP cli/test_reader_account.php remove $RSID >/dev/null && $PHP cli/sweep_test_posts.php && $PHP cli/push_pause.php off"' EXIT
# Hold notifications during the run (at most 15 minutes, if the clean-up above never ran).
$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/push_pause.php 900"

set -- $TOPIC
export SITE_URL COOKIE=$2
node browser_test.js "$SID" "$1" "${SHOTS:-}" "$RSID"
