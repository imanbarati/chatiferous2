#!/usr/bin/env python3
"""Fetch who voted for what in the group's old Telegram polls (the export only has counts).

Runs as the owner's own Telegram account (only a user who voted in a poll may list its voters),
read-only. Two steps:

    python3 tools/fetch_poll_voters.py login   # run it yourself: asks for phone, code, 2FA password
    python3 tools/fetch_poll_voters.py fetch   # writes import/data/poll_voters.jsonl and
                                               #   poll_anonymous.txt (resumable)
                                               #   (TG_EXPORT and TG_GROUP_ID in secrets.env, below)
    python3 tools/fetch_poll_voters.py logout  # ends the session and deletes the session file

Needs, in ~/.config/chatiferous/secrets.env: TG_API_ID and TG_API_HASH (from my.telegram.org),
TG_EXPORT (the export's result.json) and TG_GROUP_ID (the group's id, like -100…; it's the export's
"id" with -100 in front).
Output lines: {"msg": telegram message id, "pos": [answer positions], "user": telegram user id}.
Needs Telethon: pip install telethon (in a venv).
"""
import asyncio
import json
import os
import sys
from pathlib import Path

from telethon import TelegramClient, errors
from telethon.tl.functions.messages import GetPollVotesRequest

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'import/data/poll_voters.jsonl'
ANON = ROOT / 'import/data/poll_anonymous.txt'       # anonymous polls, for import_poll_voters.php
DONE = ROOT / 'import/data/poll_voters.done'          # telegram message ids already fetched
SESSION = Path.home() / '.config/chatiferous/telegram_voters'


def secrets():
    env = {}
    for line in (Path.home() / '.config/chatiferous/secrets.env').read_text().splitlines():
        if '=' in line and not line.lstrip().startswith('#'):
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip().strip('"\'')
    return env


def config():
    env = secrets()
    return int(env['TG_API_ID']), env['TG_API_HASH']


def client():
    api_id, api_hash = config()
    # Sleep through Telegram's rate limits rather than fail (they can ask for minutes).
    return TelegramClient(str(SESSION), api_id, api_hash, flood_sleep_threshold=3600)


def poll_ids():
    """Message ids of the polls the owner voted in (others can't be listed)."""
    data = json.loads(Path(secrets()['TG_EXPORT']).expanduser().read_text())
    return [m['id'] for m in data['messages']
            if 'poll' in m and any(a.get('chosen') for a in m['poll'].get('answers', []))]


async def login():
    async with client() as c:          # start(): prompts for phone, code and 2FA password
        me = await c.get_me()
        print(f'Signed in as {me.first_name} (@{me.username}). The session is saved; you can run "fetch" now.')
    os.chmod(str(SESSION) + '.session', 0o600)


async def fetch():
    ids = poll_ids()
    done = set(int(x) for x in DONE.read_text().split()) if DONE.exists() else set()
    todo = [i for i in ids if i not in done]
    print(f'{len(ids)} polls; {len(done)} already fetched; {len(todo)} to go.', flush=True)
    c = client()
    await c.connect()
    if not await c.is_user_authorized():
        sys.exit('Not signed in: run "login" first.')
    group = await c.get_entity(int(secrets()['TG_GROUP_ID']))
    with OUT.open('a') as out, DONE.open('a') as done_f, ANON.open('a') as anon:
        for n in range(0, len(todo), 100):
            batch = todo[n:n + 100]
            msgs = await c.get_messages(group, ids=batch)
            for mid, msg in zip(batch, msgs):
                poll = getattr(getattr(msg, 'media', None), 'poll', None) if msg else None
                if poll is None:
                    print(f'  {mid}: no longer a poll on Telegram, skipped', flush=True)
                elif not poll.public_voters:
                    print(f'  {mid}: anonymous poll, skipped', flush=True)
                    anon.write(f'{mid}\n')
                else:
                    pos = {a.option: i for i, a in enumerate(poll.answers)}
                    offset, seen = None, 0
                    try:
                        while True:
                            r = await c(GetPollVotesRequest(peer=group, id=mid, limit=50, offset=offset))
                            for v in r.votes:
                                opts = getattr(v, 'options', None) or [getattr(v, 'option', None)]
                                user = getattr(v.peer, 'user_id', None)
                                picked = [pos[o] for o in opts if o in pos]
                                if user and picked:
                                    out.write(json.dumps({'msg': mid, 'pos': picked, 'user': user}) + '\n')
                                    seen += 1
                            if not r.next_offset or r.next_offset == offset:
                                break
                            offset = r.next_offset
                    except errors.RPCError as e:
                        print(f'  {mid}: {e.__class__.__name__}, skipped', flush=True)
                        continue
                out.flush()
                done_f.write(f'{mid}\n')
                done_f.flush()
            print(f'{min(n + 100, len(todo))}/{len(todo)} polls done', flush=True)
    await c.disconnect()
    print(f'Finished. Voters are in {OUT}')


async def logout():
    async with client() as c:
        await c.log_out()                  # ends the session on Telegram's side and deletes the file
    print('Signed out; the session is gone.')


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd not in ('login', 'fetch', 'logout'):
        sys.exit(__doc__)
    asyncio.run({'login': login, 'fetch': fetch, 'logout': logout}[cmd]())
