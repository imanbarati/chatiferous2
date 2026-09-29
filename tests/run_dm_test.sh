#!/bin/sh
# Runs tests/dm_test.js: makes two temporary members (with a real password) and their sign-in
# sessions, and removes them, their keys, DMs and files afterwards however the run ends.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/tests"
. "$ROOT/tools/site.sh"
[ -d node_modules/puppeteer-core ] || npm install --silent
SSH="$SSH $HOST"
COOKIE=$($SSH "cd ~/$REMOTE_APP && $PHP -r 'require \"lib/bootstrap.php\"; echo config(\"session_name\");'")
export SITE_URL COOKIE PHP REMOTE_APP SSH_CMD="$SSH"
PW="dm-test-$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
SA=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
SB=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
IDS=$($SSH "cd ~/$REMOTE_APP && $PHP -r '
  require \"lib/bootstrap.php\";
  \$h = password_hash(\"$PW\", PASSWORD_DEFAULT);
  foreach ([\"A\", \"B\"] as \$x) {
    q(\"INSERT INTO users (display_name, username, role, status, color_index, claimed_at, password_hash) VALUES (?, ?, \\\"member\\\", \\\"active\\\", 3, UTC_TIMESTAMP(), ?)\", [\"Automated Test \$x\", \"autotest\" . strtolower(\$x), \$h]);
    echo db()->lastInsertId(), \" \";
  }'")
set -- $IDS
IDA=$1; IDB=$2
$SSH "cd ~/$REMOTE_APP && printf 'user_id|i:$IDA;csrf|s:32:\"%s\";' $SA > sessions/sess_$SA && printf 'user_id|i:$IDB;csrf|s:32:\"%s\";' $SB > sessions/sess_$SB && chmod 600 sessions/sess_$SA sessions/sess_$SB"
trap '$SSH "cd ~/$REMOTE_APP && rm -f sessions/sess_$SA sessions/sess_$SB && $PHP -r '"'"'
  require \"lib/bootstrap.php\";
  foreach (q(\"SELECT id FROM topics WHERE kind = \\\"dm\\\" AND (dm_a IN ($IDA, $IDB) OR dm_b IN ($IDA, $IDB))\")->fetchAll(PDO::FETCH_COLUMN) as \$t) {
    foreach (q(\"SELECT a.path FROM attachments a JOIN messages m ON m.id = a.message_id WHERE m.topic_id = ?\", [\$t])->fetchAll(PDO::FETCH_COLUMN) as \$p) { @unlink(config(\"uploads_dir\") . \"/\" . \$p); }
    q(\"DELETE FROM changes WHERE topic_id = ?\", [\$t]); q(\"DELETE FROM messages WHERE topic_id = ?\", [\$t]); q(\"DELETE FROM topics WHERE id = ?\", [\$t]);
  }
  foreach (q(\"SELECT path FROM attachments WHERE uploader_id IN ($IDA, $IDB) AND message_id IS NULL\")->fetchAll(PDO::FETCH_COLUMN) as \$p) { @unlink(config(\"uploads_dir\") . \"/\" . \$p); }
  q(\"DELETE FROM attachments WHERE uploader_id IN ($IDA, $IDB) AND message_id IS NULL\");
  q(\"DELETE FROM user_keys WHERE user_id IN ($IDA, $IDB)\"); q(\"DELETE FROM read_state WHERE user_id IN ($IDA, $IDB)\");
  q(\"DELETE FROM users WHERE id IN ($IDA, $IDB)\"); echo \"cleaned up\n\";'"'"'"' EXIT
node dm_test.js "$SA" "$SB" "$IDA" "$IDB" "$PW"
