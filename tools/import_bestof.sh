#!/bin/sh
# Sends the curated "best of" commentary to the server and imports whatever is new.
#
# The curation lives on this machine (SynologyDrive), which the server cannot see, so the Markdown
# goes up first and the import runs there. Safe to run as often as you like: unchanged chapters are
# recognised and left alone, so a daily run on a day with no new curation does nothing at all.
#
#   tools/import_bestof.sh [--force]
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
. tools/site.sh

CURATED="${BESTOF_DIR:-$HOME/SynologyDrive/Religion/Commentaries/curated}"
[ -d "$CURATED" ] || { echo "No curated folder at $CURATED" >&2; exit 1; }

# Run from cron every hour but do the work at most once in 20 hours, as the backup does: a laptop
# is not on at four in the morning, so a fixed nightly time would miss most days. "--now" ignores
# the guard, and "--force" re-imports every chapter whether it has changed or not.
LAST="$HOME/.cache/chatiferous-bestof.last"
mkdir -p "$(dirname "$LAST")"
if [ "$1" = "--now" ]; then
  shift
elif [ -f "$LAST" ] && [ $(( $(date +%s) - $(stat -c %Y "$LAST") )) -lt 72000 ]; then
  exit 0
fi

rsync -a --delete -e "$SSH" --chmod=D700,F600 \
  --include '*/' --include '*.md' --exclude '*' \
  "$CURATED/" "$HOST:$REMOTE_APP/data/bestof/"

$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/import_bestof.php data/bestof $*"
touch "$LAST"
