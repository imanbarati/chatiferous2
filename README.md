# Chatiferous

A self-hosted group chat with topics, built to feel like a Telegram forum group, that runs
on ordinary shared hosting (PHP and MySQL/MariaDB). No Node server, no Docker, no build step.

It was written for a Bible study group that moved off Telegram in 2026, and is used there
every day.

## What it does

- **Topics**, like Telegram's forum groups: a General topic plus as many as you like, with
  colors or icons, pinning to the top, closing, and unread counts.
- **Messages**: replies, quotes, edits, deletes, forwards, reactions (with unread counts),
  @mentions, link previews, photos, files, videos, GIFs (optional), pinned messages, polls
  (anonymous or not, single or multiple choice, changeable votes, who voted for what).
- **Direct messages** between members, **end-to-end encrypted** in the browser: the server,
  and whoever runs it, can't read them. See [SECURITY.md](SECURITY.md).
- **Live updates** without websockets (short polling, cheap on shared hosting), read receipts,
  "seen by", jump to first unread, drafts kept per topic, search.
- **Installs to a phone's home screen** (a web app) with **push notifications**.
- **Members and invites**: invite links, per-person claim links, admins, deactivation.
- Dark mode, text size, sounds.
- **Moving from Telegram** (optional): import a group's history, members and polls, and let
  people sign in with Telegram to claim their old account. See
  [MIGRATING-FROM-TELEGRAM.md](MIGRATING-FROM-TELEGRAM.md).
- Extras you can switch on: a doodle wallpaper, and a daily scheduled post with a "Finished?" poll
  (made for a reading plan).

## A short history

In-Depth Bible Study, a Bible study group led by Larry Sanger, met on Telegram from February 2023.
By September 2026 it had about 200 members and some 11,000 messages across 57 forum topics. Larry
decided to move the group to a site of its own, on the ordinary web hosting he already had.
Chatiferous was written for that move, by Larry and Claude (Anthropic's AI, working in Claude Code).
Claude wrote the code; Larry set the direction, made the design calls, and tested it hard.

- **18 September 2026, afternoon.** A plan: copy Telegram's behavior closely, but run on plain PHP
  and MySQL.
- **That evening.** The first version:
  - "Log in with Telegram" and the import of the group's whole history
  - posting, replies, reactions, polls, photos and live updates
  - a dark theme, and a drawn icon for each topic (one per book of the Bible)
  - the built-in daily reading post and poll, which replaced the group's old reading bot
  - invite links, installing to a phone, and push notifications
- **Late that night.** More of Telegram:
  - search, GIFs, drafts, forwarding, link previews and quote-replies
  - who reacted, videos, pinned messages, and send and delete sounds
  - the names on the old Telegram polls, recovered from Telegram
- **19 September.** The Telegram group closed and its members moved over. That morning brought
  direct messages, then end-to-end encryption for them. The group-specific parts became options,
  an independent security review was done and its findings fixed, and the first public release
  followed under the GPL.
- **19–20 September.** A Bible reader, built alongside the chat: swiping between chapters,
  footnotes and cross-references, search, reading settings kept on the account, a version
  downloaded to the phone so it reads with no signal, and highlights, bookmarks and private notes
  that you can export and take away. Then the commentaries: thirty public-domain works, verse by
  verse, whichever of them you choose to see.

## Install

See [INSTALL.md](INSTALL.md). In short: create a database, upload two folders, fill in
`config.php`, run `composer install`, `php cli/migrate.php` and `php cli/setup.php`, and add
the cron jobs.

**Moving a group from Telegram?** Follow [MIGRATING-FROM-TELEGRAM.md](MIGRATING-FROM-TELEGRAM.md)
instead of the last setup step. It imports the group's history, members and polls, and lets
members claim their old accounts with "Log in with Telegram".

## Layout

    app/     private code (never web-served): lib/, cli/ (commands and cron jobs), sql/ (migrations)
    web/     the public web folder: pages, api/, assets/ (JS, CSS, icons)
             assets/chat/ holds the app's script in readable parts, served as one file by a.php
    tools/   backup and helper scripts, run from your computer
    import/  Telegram export preparation
    tests/   server tests (PHP) and browser tests (Puppeteer)

## License

GPL-3.0. See [LICENSE](LICENSE).

## Credits

- Topic icons (`web/assets/topic-icons.svg`) and most wallpaper symbols
  (`web/assets/wallpaper-1.svg`) are from [game-icons.net](https://game-icons.net), by lorc,
  delapouite, carl-olsen, skoll, seregacthtuf and others, under
  [CC BY 3.0](https://creativecommons.org/licenses/by/3.0/).
- The Bible reader's texts: the World English Bible and King James Version from
  [eBible.org](https://ebible.org) and the [Berean Standard Bible](https://berean.bible), all
  public domain. Cross-references from [openbible.info](https://www.openbible.info/labs/cross-references/)
  (CC BY), drawn from the Treasury of Scripture Knowledge.
- The commentaries — thirty works, from Matthew Poole to the Expositor's Greek Testament, all out
  of copyright — are imported from [SermonIndex](https://www.sermonindex.net/commentary/)'s
  modules, [CrossWire](https://crosswire.org)'s SWORD modules, and the
  [CC0 edition](https://github.com/Isidore-Guild/catena) of the Catena Aurea in Newman's
  translation. See `cli/import_mybible.php` and `tools/`.
- Push notifications use [web-push-php](https://github.com/web-push-libs/web-push-php) (MIT).
- GIF search, when switched on, is powered by [KLIPY](https://klipy.com).
