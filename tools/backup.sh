#!/bin/sh
# Backs up the live site to this computer: a gzipped database dump (kept 14 days)
# and a mirror of uploaded photos/files/avatars. Runs hourly from cron but only
# does the work if the last backup is more than 20 hours old (laptops sleep).
# Force a run with: tools/backup.sh --now
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tools/site.sh"
SSH="$SSH -o ConnectTimeout=20"
DEST="$BACKUP_DIR"
P="${BACKUP_PREFIX:-chatiferous}"
mkdir -p "$DEST/db" "$DEST/uploads"
chmod 700 "$DEST"

LAST="$DEST/.last-success"
if [ "$1" != "--now" ] && [ -f "$LAST" ] && [ $(( $(date +%s) - $(stat -c %Y "$LAST") )) -lt 72000 ]; then
  exit 0
fi

STAMP=$(date -u +%Y-%m-%d_%H%M)
TMP="$DEST/db/.partial.sql.gz"
$SSH "$HOST" "$PHP ~/$REMOTE_APP/cli/db_dump.php" > "$TMP"
# A real dump is never tiny; refuse to keep a broken one.
[ "$(stat -c %s "$TMP")" -gt 100000 ] || { echo "backup: dump too small, keeping previous backups" >&2; rm -f "$TMP"; exit 1; }
gzip -t "$TMP"
mv "$TMP" "$DEST/db/$P-$STAMP.sql.gz"
chmod 600 "$DEST/db/$P-$STAMP.sql.gz"

rsync -a -e "$SSH" "$HOST:$REMOTE_APP/uploads/" "$DEST/uploads/"

# Keep the newest 14 dumps.
ls -1t "$DEST"/db/$P-*.sql.gz | tail -n +15 | xargs -r rm -f
touch "$LAST"
echo "$(date -u '+%F %T') UTC backup ok: $(du -sh "$DEST" | cut -f1) total" >> "$DEST/backup.log"
