#!/bin/sh
# Deploys to your server (settings in site.env): app/ -> ~/$REMOTE_APP (private), web/ -> ~/$REMOTE_WEB (public).
# Server-only files (config.php, sessions, data, uploads) are never touched.
# Then applies database changes and resets PHP's OPcache, which otherwise keeps old code for up to 90 seconds.
set -e
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
. tools/site.sh

rsync -a --delete -e "$SSH" --chmod=D700,F600 \
  --exclude config.php --exclude config.test.php --exclude sessions/ --exclude data/ --exclude uploads/ --exclude vendor/ --exclude composer.lock \
  app/ "$HOST:$REMOTE_APP/"
# .htaccess goes up separately, with HTACCESS_EXTRA (if any) on top, so it's never briefly missing it.
rsync -a --delete -e "$SSH" --chmod=D755,F644 --exclude /.htaccess web/ "$HOST:$REMOTE_WEB/"
HT=$(mktemp); { [ -n "$HTACCESS_EXTRA" ] && printf '%s\n' "$HTACCESS_EXTRA"; cat web/.htaccess; } > "$HT"
rsync -e "$SSH" --chmod=F644 "$HT" "$HOST:$REMOTE_WEB/.htaccess"; rm -f "$HT"

$SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/migrate.php"

# OPcache belongs to the web server's PHP, so reset it with a one-off web request.
TOKEN=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
$SSH "$HOST" "echo '<?php opcache_reset(); echo \"ok\";' > ~/$REMOTE_WEB/opcache_$TOKEN.php"
printf 'OPcache reset: '
curl -s -A 'Mozilla/5.0 (deploy) Chrome/140.0' "${SITE_URL}opcache_$TOKEN.php"
echo
$SSH "$HOST" "rm -f ~/$REMOTE_WEB/opcache_$TOKEN.php"
