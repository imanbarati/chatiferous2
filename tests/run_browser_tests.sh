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
# On exit, however the run ended (even a crash): end the session, and delete any clearly-marked
# test posts from the last hour that the test itself didn't get to clean up.
trap '$SSH "$HOST" "rm -f ~/$REMOTE_APP/sessions/sess_$SID; cd ~/$REMOTE_APP && $PHP cli/sweep_test_posts.php; $PHP cli/push_pause.php off"' EXIT
# Hold notifications during the run (at most 15 minutes, if the clean-up above never ran).
$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/push_pause.php 900"

set -- $TOPIC
export SITE_URL COOKIE=$2
node browser_test.js "$SID" "$1" "${SHOTS:-}"
