#!/bin/sh
# Runs tests/server_tests.php on the server against a throwaway test database
# (~/$REMOTE_APP/config.test.php: a copy of config.php naming an empty database, plus
# 'test_database' => true and 'daily_reading' => true). The code under test is copied to its
# own folder, ~/chatiferous-tests/app — never over the live site's code (~/$REMOTE_APP), which
# only deploy.sh updates (together with the database changes that code needs).
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
. tools/site.sh

rsync -a --delete -e "$SSH" --chmod=D700,F600 --exclude config.php --exclude config.test.php \
  --exclude sessions/ --exclude data/ --exclude uploads/ --exclude vendor/ --exclude composer.lock \
  app/ "$HOST:chatiferous-tests/app/"
rsync -a -e "$SSH" --chmod=D700,F600 tests/server_tests.php "$HOST:chatiferous-tests/"
$SSH "$HOST" "cd ~/chatiferous-tests/app && install -m 600 ~/$REMOTE_APP/config.test.php config.test.php \
  && ln -sfn ~/$REMOTE_APP/vendor vendor && mkdir -p -m 700 data sessions uploads \
  && $PHP ~/chatiferous-tests/server_tests.php"
