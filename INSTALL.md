# Installing Chatiferous

Chatiferous runs on ordinary shared hosting. It was built on a cPanel host, and these steps assume
one like it. A VPS works the same way.

## What you need

- **PHP 8.2 or later** with the pdo_mysql, curl, gd, mbstring and openssl extensions (most hosts
  have all of these).
- **MySQL 5.7+ or MariaDB 10.3+**, and one empty database.
- **Apache** (or LiteSpeed) with `.htaccess` and mod_rewrite, which most shared hosts have.
- **HTTPS**. It's required: browsers only allow notifications, installing to the home screen and the
  encryption used by direct messages on secure sites.
- **Cron jobs** and **SSH access**. On cPanel, both are under *Advanced*.
- **Composer**, to fetch the one library used for push notifications. Many hosts have it; if yours
  doesn't, run `composer install` on your own computer and upload the `vendor/` folder.

## 1. Put the files in place

The code is in two folders:

- **`app/`** holds the private code, settings and uploaded files. It must **not** be reachable from
  the web. Upload it to a folder named **`chatiferous` in your home directory** (`~/chatiferous`).
- **`web/`** is the public part. Upload its contents to the web folder where the chat will live.
  For example, `~/public_html/chat` for `https://example.com/chat/`, or `~/public_html` itself
  for a whole (sub)domain.

`web/boot.php` finds the app folder by looking for `chatiferous` next to the web folder or next to
its parent.

In the app folder, make the private folders:

    cd ~/chatiferous && mkdir -m 700 uploads sessions data

## 2. Make the database and settings

1. Create a database and a user with all privileges on it. On cPanel, use *MySQL Databases*.
2. Copy `app/config.sample.php` to `~/chatiferous/config.php`, fill in the database details,
   `base_url` (the web address path, e.g. `/chat/`, or `/` for a whole domain) and `group_name`,
   then run:

        chmod 600 ~/chatiferous/config.php

3. Install the push library, then make your push-notification keys and paste the two lines they
   print into `config.php`:

        cd ~/chatiferous && composer install --no-dev
        php cli/make_vapid_keys.php

   Also set `vapid_subject` to a `mailto:` address of yours. Push services use it to contact you
   if something goes wrong.

4. Create the tables, then your own account (you'll be the owner) and the General topic:

        php cli/migrate.php
        php cli/setup.php

On cPanel, `php` on the command line may be an older version than the website uses. If so, use
the full path, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`.

## 3. PHP version (cPanel)

If the web folder should use a newer PHP than the rest of your site, pick it for that folder in
*MultiPHP Manager*. Alternatively, put the handler line at the top of `web/.htaccess`, e.g.

    AddHandler application/x-httpd-ea-php83 .php .php8 .phtml

If you deploy with `deploy.sh`, set this as `HTACCESS_EXTRA` in `site.env` instead (see *Deploying updates* below).

## 4. Cron jobs

Replace `php` with your PHP path if needed:

    # push notifications for new messages
    * * * * * php $HOME/chatiferous/cli/push_send.php >> $HOME/chatiferous/data/push.log 2>&1
    # daily tidy-up: abandoned uploads, the old change log, stale sessions
    30 4 * * * php $HOME/chatiferous/cli/housekeeping.php >> $HOME/chatiferous/data/housekeeping.log 2>&1
    # only if you switch on 'daily_reading'
    */15 * * * * php $HOME/chatiferous/cli/daily_reading.php >> $HOME/chatiferous/data/daily_reading.log 2>&1

## 5. Sign in and invite people

Open your chat's address and sign in with the username and password you chose in `setup.php`.
Then:

- Create topics with the round ✎ button at the bottom of the topic list (admins only).
- Invite people from the menu: *Invite someone* makes a link to send them.
- Admins can manage members under *Members*.

## 6. Optional

These go in `config.php`; `config.sample.php` lists them all.

- **GIFs.** Create an account in the [KLIPY Partner Panel](https://partner.klipy.com) and generate
  an API key. A test key allows 100 searches an hour; apply for a production key there when you're
  ready. Put it in as `klipy_key`. Without a key, the GIF button doesn't appear.
- **Name and look.** Set `short_name` (the name under the home-screen icon), `group_emoji`, and
  `app_icons` (a folder in `web/` with your own `favicon-32.png`, `apple-touch-icon.png` (180 px),
  `icon-192.png` and `icon-512.png`).
- **Wallpaper.** `'wallpaper' => true` draws `web/assets/wallpaper-1.svg` (a doodle of Bible symbols) as a faint pattern behind
  messages. Replace that file with your own pattern if you like.
- **Daily reading.** `'daily_reading' => true` adds *Daily reading* to the admin menu. Choose the
  topic, time and posting account there, then upload a schedule (CSV). Each day it posts that day's
  entry and a "Finished?" poll. Add the third cron job above.
- **Moving from Telegram.** See [MIGRATING-FROM-TELEGRAM.md](MIGRATING-FROM-TELEGRAM.md).

## Deploying updates from your computer

Copy `site.env.sample` to `site.env`, fill in your server's details and SSH key, then run
`./deploy.sh`. It uploads both folders (never touching `config.php`, uploads or sessions), applies
any database changes, and clears PHP's code cache. `tools/backup.sh` copies the database and
uploads to your computer (run it from cron).

## Tests

- `tests/run_server_tests.sh` runs the PHP tests on the server against a separate, empty database.
  It needs `~/chatiferous/config.test.php`: a copy of `config.php` naming that database, with
  `'test_database' => true` and `'daily_reading' => true` added.
- `tests/run_browser_tests.sh` and `tests/run_dm_test.sh` drive a real browser (Chromium, via
  Puppeteer; run `npm install` in `tests/` first) against your live site. The browser tests post
  clearly marked messages in a topic called "Platform" and delete them afterwards. The DM test uses
  two temporary accounts and removes them.
