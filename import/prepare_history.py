#!/usr/bin/env python3
"""Turn a Telegram group export (JSON) into clean data for the importers.

Writes, into OUT_DIR:
  members.json  every person seen (posted, acted, reacted, was mentioned); for cli/import_members.php
  history.json  topics and messages; for cli/import_history.php
  media.txt     export-relative paths of media files to upload (one per line)

Usage: python3 import/prepare_history.py EXPORT_DIR/result.json OUT_DIR [RENAMES.json]
Nothing here is committed: the export and outputs live in import/data/ (git-ignored).

Topic renames: Telegram's export records each rename but not which topic was renamed, so renamed
topics keep the name they were created with, and the renames are listed at the end for you to
redo in the app (topic ⋮ → Edit). Or, before importing, map them in RENAMES.json:
{"<rename message id>": <topic id, i.e. its "topic created" message id>, ...}
"""
import json
import os
import sys
from datetime import datetime, timezone

src, out_dir = sys.argv[1], sys.argv[2]
os.makedirs(out_dir, exist_ok=True)
EDIT_TARGETS = {int(k): int(v) for k, v in json.load(open(sys.argv[3])).items()} if len(sys.argv) > 3 else {}
chat = json.load(open(src, encoding="utf-8"))
msgs = chat["messages"]
by_id = {m["id"]: m for m in msgs}

KEEP_ENTITIES = {"bold", "italic", "underline", "strikethrough", "spoiler", "code", "pre",
                 "text_link", "link", "email", "mention", "mention_name", "hashtag", "blockquote"}
MISSING = "(File not included"


def utc(unix):
    return datetime.fromtimestamp(int(unix), timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def tg_user(pid):
    return int(pid[4:]) if isinstance(pid, str) and pid.startswith("user") else None


def utf16_len(s):
    return len(s.encode("utf-16-le")) // 2


def text_and_entities(m):
    """Telegram exports formatting as labelled pieces; rebuild text + offset entities."""
    text, ents = "", []
    for piece in m.get("text_entities", []):
        t, kind = piece["text"], piece["type"]
        if kind in KEEP_ENTITIES and t:
            e = {"type": {"link": "url", "strikethrough": "strike"}.get(kind, kind),
                 "offset": utf16_len(text), "length": utf16_len(t)}
            if kind == "text_link":
                e["url"] = piece.get("href", "")
            if kind == "mention_name":
                e["tg_user_id"] = piece.get("user_id")
            if kind == "pre" and piece.get("language"):
                e["language"] = piece["language"]
            ents.append(e)
        text += t
    return text, ents


# ---- Topics ----
topics = {1: {"tg_id": 1, "title": "General", "is_general": True, "creator": None, "created_at": None}}
for m in msgs:
    if m.get("action") == "topic_created":
        topics[m["id"]] = {"tg_id": m["id"], "title": m["title"], "is_general": False,
                           "creator": tg_user(m.get("actor_id")), "created_at": utc(m["date_unixtime"])}
for m in msgs:
    if m.get("action") == "topic_edit" and m.get("new_title") and m["id"] in EDIT_TARGETS:
        topics[EDIT_TARGETS[m["id"]]]["title"] = m["new_title"]  # messages are in order, so the last rename wins
topics[1]["created_at"] = utc(msgs[0]["date_unixtime"])


def topic_of(m):
    seen = 0
    while m and seen < 1000:
        r = m.get("reply_to_message_id")
        if m["id"] in topics and m.get("action") == "topic_created":
            return m["id"]
        if r is None:
            return 1
        if r in topics:
            return r
        m, seen = by_id.get(r), seen + 1
    return 1


# ---- People ----
people = {}  # tg id -> (date, name)


def saw(pid, name, date):
    tg = tg_user(pid)
    if tg is None:
        return
    prev = people.get(tg)
    if prev is None or (date >= prev[0] and name) or (prev[1] is None and name):
        people[tg] = (date, name if name else (prev[1] if prev else None))


bot_ids = set()
for m in msgs:
    d = m.get("date_unixtime", "0")
    saw(m.get("from_id"), m.get("from"), d)
    saw(m.get("actor_id"), m.get("actor"), d)
    for r in m.get("reactions") or []:
        for who in r.get("recent", []):
            saw(who.get("from_id"), who.get("from"), d)
    for e in m.get("text_entities", []):
        if e["type"] == "mention_name" and e.get("user_id"):
            saw("user%d" % e["user_id"], None, d)
    if (m.get("from") or "").lower().endswith("bot"):
        bot_ids.add(tg_user(m.get("from_id")))

members = [{"telegram_id": tg, "name": name or "", "deleted": not name, "bot": tg in bot_ids}
           for tg, (_, name) in sorted(people.items())]

# ---- Messages ----
out_msgs, media = [], []
for m in msgs:
    action = m.get("action")
    rec = {"tg_id": m["id"], "created_at": utc(m["date_unixtime"])}
    if m["type"] == "service":
        if action == "topic_created":
            rec.update(kind="service", topic=m["id"], service={"action": "topic_created", "title": m["title"]})
        elif action == "topic_edit" and m.get("new_title") and m["id"] in EDIT_TARGETS:
            rec.update(kind="service", topic=EDIT_TARGETS[m["id"]], service={"action": "topic_renamed", "title": m["new_title"]})
        elif action == "pin_message" and m.get("message_id") in by_id:
            rec.update(kind="service", topic=topic_of(by_id[m["message_id"]]),
                       service={"action": "pinned", "tg_message_id": m["message_id"]})
        else:
            continue  # joins, invites, group photo changes: not shown
        rec["from"] = tg_user(m.get("actor_id"))
        rec["text"], rec["entities"] = "", []
        out_msgs.append(rec)
        continue

    text, ents = text_and_entities(m)
    rec.update(kind="poll" if "poll" in m else "text", topic=topic_of(m), from_=tg_user(m.get("from_id")),
               text=text, entities=ents)
    rec["from"] = rec.pop("from_")
    r = m.get("reply_to_message_id")
    if r is not None and r not in topics and r in by_id and by_id[r]["type"] == "message":
        rec["reply_to"] = r
    if m.get("edited_unixtime"):
        rec["edited_at"] = utc(m["edited_unixtime"])
    if m.get("forwarded_from"):
        rec["forwarded_from"] = m["forwarded_from"]
    if "poll" in m:
        p = m["poll"]
        rec["poll"] = {"question": p["question"], "closed": True,
                       "options": [{"text": a["text"].lstrip("￼"), "votes": a["voters"]} for a in p["answers"]]}
    att = None
    if "photo" in m:
        ok = not m["photo"].startswith(MISSING)
        att = {"kind": "photo", "src": m["photo"] if ok else None, "name": "", "mime": "image/jpeg",
               "size": m.get("photo_file_size", 0), "width": m.get("width"), "height": m.get("height")}
    elif "file" in m:
        ok = not m["file"].startswith(MISSING)
        kind = {"video_file": "video", "voice_message": "voice", "animation": "animation",
                "video_message": "video"}.get(m.get("media_type"), "file")
        att = {"kind": kind, "src": m["file"] if ok else None, "name": m.get("file_name", ""),
               "mime": m.get("mime_type", ""), "size": m.get("file_size", 0), "width": m.get("width"),
               "height": m.get("height"), "duration": m.get("duration_seconds")}
    if att:
        rec["attachment"] = att
        if att["src"]:
            media.append(att["src"])
    reacts = []
    for rx in m.get("reactions") or []:
        if rx.get("type") != "emoji":
            continue
        users = [tg_user(w.get("from_id")) for w in rx.get("recent", []) if tg_user(w.get("from_id"))]
        reacts.append({"emoji": rx["emoji"], "users": users, "extra": max(0, rx["count"] - len(users)),
                       "dates": [utc(datetime.fromisoformat(w["date"]).timestamp()) for w in rx.get("recent", [])]})
    if reacts:
        rec["reactions"] = reacts
    out_msgs.append(rec)

pins = [{"tg_message_id": m["message_id"], "by": tg_user(m.get("actor_id")), "at": utc(m["date_unixtime"])}
        for m in msgs if m.get("action") == "pin_message" and m.get("message_id") in by_id]

json.dump(members, open(f"{out_dir}/members.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
json.dump({"topics": list(topics.values()), "messages": out_msgs, "pins": pins},
          open(f"{out_dir}/history.json", "w", encoding="utf-8"), ensure_ascii=False)
open(f"{out_dir}/media.txt", "w").write("\n".join(sorted(set(media))) + "\n")

unplaced = [m for m in msgs if m.get("action") == "topic_edit" and m.get("new_title") and m["id"] not in EDIT_TARGETS]
if unplaced:
    print(f"{len(unplaced)} topic rename(s) the export can't place (rename those topics in the app afterwards: topic ⋮ → Edit; or map them first, see the top of import/prepare_history.py):")
    for m in unplaced:
        print(f'  message {m["id"]}, {m["date"][:10]}: renamed to "{m["new_title"]}"')
print(f"{len(members)} people ({sum(x['deleted'] for x in members)} deleted, {sum(x['bot'] for x in members)} bots), "
      f"{len(topics)} topics, {len(out_msgs)} messages, {len(pins)} pins, {len(set(media))} media files")
