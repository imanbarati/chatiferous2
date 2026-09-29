# Moving a group from Telegram

Chatiferous can take over a Telegram group, forum topics included. It brings over:

- the whole history, with photos, files and videos
- members, as accounts waiting to be claimed
- polls, with who voted for what

Members then sign in once with **Log in with Telegram**, which connects them to their old account
and its history. After that they can set a username and password.

Do a normal install first ([INSTALL.md](INSTALL.md)), but **skip `cli/setup.php`**: the import
makes you the owner.

## 1. A login bot

1. In Telegram, talk to [@BotFather](https://t.me/botfather), send `/newbot` and follow the steps.
   Keep the token it gives you secret.
2. Send `/setdomain` to @BotFather, choose the bot, and enter your chat's domain (e.g. `example.com`).
   This lets the bot's sign-in button work on your site.
3. Add the bot to your group and **make it an administrator** (it needs no permissions). Telegram
   only guarantees that a bot can check who's in a group when it's an admin, and that check is how
   Chatiferous knows who may sign in.
4. In `config.php`, set `telegram_bot_username` (without the @), `telegram_bot_token`, and
   `telegram_group_id`. The group ID is the export's `"id"` (next step) with `-100` in front, so
   `1234567890` becomes `-1001234567890`.

Without all three settings, the Telegram button doesn't appear and Telegram sign-ins are refused.

## 2. Export the group

In **Telegram Desktop**, open the group, choose **⋮ → Export chat history**, tick photos, videos
and files, set the format to **Machine-readable JSON**, and export. You'll get a folder with
`result.json` and the media. (Export the group itself this way, not your whole account from
Settings: the importer expects one group's export.)

The export only has totals for polls, not who voted. The optional step 5 below fetches the voters.

## 3. Prepare and upload

On your computer (Python 3):

    python3 import/prepare_history.py EXPORT_FOLDER/result.json import/data

This writes `members.json`, `history.json` and `media.txt` (the media files to upload) into
`import/data/`, which is never committed.

It also lists any **topic renames** it couldn't place. Telegram's export records that a topic was
renamed, but not which topic, so a renamed topic arrives under the name it was created with.
Rename those topics in the app afterwards (topic ⋮ → Edit). Alternatively, map them before
importing, as the notes at the top of `import/prepare_history.py` explain.

Then upload them:

    rsync -a import/data/members.json import/data/history.json you@host:chatiferous/data/
    rsync -a --files-from=import/data/media.txt EXPORT_FOLDER/ you@host:chatiferous/uploads/import/

## 4. Import

On the server:

    cd ~/chatiferous
    php cli/import_members.php data/members.json --owner=YOUR_TELEGRAM_ID
    php cli/import_history.php data/history.json
    php cli/fetch_avatars.php

- `--owner` is your numeric Telegram ID. Your account becomes the owner.
- Bots that posted in the group become system accounts. `--bot-name="Daily Reading"` renames them.
- `fetch_avatars.php` fetches profile photos through the bot, where people's privacy settings
  allow it.

All three can be run again safely. To catch up on messages posted since your export, export again
and re-run steps 3 and 4. Only what's new is added.

## 5. Poll voters (optional)

Telegram's export doesn't say who voted for what. `tools/fetch_poll_voters.py` asks Telegram
directly, signed in as **you**: only someone who voted in a poll may see its voters, and it only
reads. It needs [Telethon](https://docs.telethon.dev) (`pip install telethon`) and an API ID and
hash from [my.telegram.org](https://my.telegram.org). Put these in
`~/.config/chatiferous/secrets.env`:

    TG_API_ID=...
    TG_API_HASH=...
    TG_EXPORT=/path/to/EXPORT_FOLDER/result.json
    TG_GROUP_ID=-100...

Then:

    python3 tools/fetch_poll_voters.py login    # asks for your phone, the code, and your 2FA password
    python3 tools/fetch_poll_voters.py fetch    # writes import/data/poll_voters.jsonl
    python3 tools/fetch_poll_voters.py logout   # ends that Telegram session

Upload `poll_voters.jsonl` and `poll_anonymous.txt` (the polls that were anonymous on Telegram)
from `import/data/` to `~/chatiferous/data/`. Then run `php cli/import_poll_voters.php --dry` to
check, and again without `--dry`. Votes are only brought over for polls you voted in yourself, and
for people who have accounts here.

## 6. Switch over

Tell the group the new address. Each person taps **Log in with Telegram** once. Anyone who isn't in
the Telegram group can be invited with a link instead.
