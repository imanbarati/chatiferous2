<?php
// Copy to config.php (never committed) and fill in. See INSTALL.md.
return [
    // ---- Required ----
    'db_host'  => 'localhost',
    'db_name'  => '',
    'db_user'  => '',
    'db_pass'  => '',

    'base_url'   => '/chat/',              // URL path of the web/ folder, with slashes at both ends
    'group_name' => 'My Group',            // shown at the top, on sign-in, and in notifications

    // Private folder for uploaded files (outside the web folder).
    'uploads_dir' => __DIR__ . '/uploads',
    // Private folder for PHP sessions (so logins last 90 days on shared hosting).
    'session_dir' => __DIR__ . '/sessions',

    // Push notifications (Web Push). Make a key pair with: php cli/make_vapid_keys.php
    'vapid_public'  => '',
    'vapid_private' => '',
    'vapid_subject' => 'mailto:you@example.com',

    // ---- Optional ----
    // 'short_name'   => 'My Group',        // name under the home-screen icon (default: group_name)
    // 'group_emoji'  => '💬',              // the group's picture
    // 'app_icons'    => 'assets/icons/',   // folder (in web/) with favicon-32, apple-touch-icon, icon-192 and icon-512 .png
    // 'session_name' => 'chatiferous_sid', // sign-in cookie name
    // 'signin_note'  => '',                // a line on the sign-in page, e.g. "Accounts from our main site don't work here."

    // GIF search, through KLIPY (free key at https://partner.klipy.com). Off without a key.
    // 'klipy_key' => '',

    // "Log in with Telegram", for groups moving from Telegram (see MIGRATING-FROM-TELEGRAM.md).
    // Off unless all three are set.
    // 'telegram_bot_username' => '',       // the login bot, without the @
    // 'telegram_bot_token'    => '',
    // 'telegram_group_id'     => 0,        // members of this group may sign in

    // Bible reader (import texts afterwards: php cli/import_bible.php WEB, then BSB, KJV).
    // 'bible_reader'   => false,
    // 'bible_versions' => ['KJV', 'WEB', 'BSB'],   // the first is what a new reader opens in
    // 'biblehub_links' => true,           // the commentary panel offers Bible Hub as a "more" link
    // 'commentaries'   => true,           // the commentary panel (php cli/import_commentary.php)

    // Extras.
    // 'wallpaper'     => false,            // doodle pattern behind messages (assets/wallpaper-1.svg: Bible symbols; swap in your own)
    // 'daily_reading' => false,            // daily scheduled post and "Finished?" poll (Admin → Daily reading)
];
