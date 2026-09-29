#!/bin/sh
# Runs tests/reader_test.js against the live site as a throwaway member, which is made here and
# taken away afterwards. It reads and never writes, so nobody's conversation is disturbed.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/tests"
. "$ROOT/tools/site.sh"
[ -d node_modules/puppeteer-core ] || npm install --silent

SID=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
CSRF=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/test_reader_account.php make $SID $CSRF" >/dev/null
trap '$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/test_reader_account.php remove $SID >/dev/null"' EXIT

COOKIE=$($SSH "$HOST" "cd ~/$REMOTE_APP && $PHP -r 'require \"lib/bootstrap.php\"; echo config(\"session_name\");'")
export SITE_URL COOKIE
node reader_test.js "$SID"
