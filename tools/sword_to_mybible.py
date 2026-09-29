#!/usr/bin/env python3
"""Turn a SWORD commentary module into a MyBible SQLite file, which the app already imports.

    python3 tools/sword_to_mybible.py <unzipped module dir> <versification.json> <out.SQLite3> \
        [--name "Matthew Henry's Concise Commentary"]

SWORD stores a commentary as one entry per verse of the KJV, in index order, with headings
for the testament, each book and each chapter sitting in front of the verses they introduce.
Both shapes are handled: zCom (bzs/bzv/bzz, zlib-compressed) and RawCom (a plain file with a
.vss index). The versification file is {BOOK: {chapter: last verse}} — the app can print its
own from the KJV it already holds.

Entries that repeat (Matthew Henry comments on a section, and SWORD links every verse in it to
the same text) are written once, over the range of verses that share them.
"""
import argparse
import json
import os
import re
import sqlite3
import struct
import sys
import zlib

MYBIBLE = {
    'GEN': 10, 'EXO': 20, 'LEV': 30, 'NUM': 40, 'DEU': 50, 'JOS': 60, 'JDG': 70, 'RUT': 80,
    '1SA': 90, '2SA': 100, '1KI': 110, '2KI': 120, '1CH': 130, '2CH': 140, 'EZR': 150,
    'NEH': 160, 'EST': 190, 'JOB': 220, 'PSA': 230, 'PRO': 240, 'ECC': 250, 'SNG': 260,
    'ISA': 290, 'JER': 300, 'LAM': 310, 'EZK': 330, 'DAN': 340, 'HOS': 350, 'JOL': 360,
    'AMO': 370, 'OBA': 380, 'JON': 390, 'MIC': 400, 'NAM': 410, 'HAB': 420, 'ZEP': 430,
    'HAG': 440, 'ZEC': 450, 'MAL': 460, 'MAT': 470, 'MRK': 480, 'LUK': 490, 'JHN': 500,
    'ACT': 510, 'ROM': 520, '1CO': 530, '2CO': 540, 'GAL': 550, 'EPH': 560, 'PHP': 570,
    'COL': 580, '1TH': 590, '2TH': 600, '1TI': 610, '2TI': 620, 'TIT': 630, 'PHM': 640,
    'HEB': 650, 'JAS': 660, '1PE': 670, '2PE': 680, '1JN': 690, '2JN': 700, '3JN': 710,
    'JUD': 720, 'REV': 730,
}
ORDER = list(MYBIBLE)
OT, NT = ORDER[:39], ORDER[39:]


def keys_for(books, versification):
    """The verse keys of one testament, in SWORD's index order (headings included as None)."""
    keys = [None, None]                             # the module's heading, then the testament's
    for book in books:
        chapters = versification[book]
        keys.append(None)                           # the book's heading
        for c in range(1, len(chapters) + 1):
            keys.append(None)                       # the chapter's heading
            for v in range(1, chapters[str(c)] + 1):
                keys.append((book, c, v))
    return keys


def read_zcom(base, want):
    """(offset, size) per index, and a reader for the compressed blocks.

    zCom indexes an entry in 10 bytes, zCom4 (for entries over 64 KB) in 12; whichever gives the
    number of entries this testament should have is the one the module uses.
    """
    with open(base + '.bzv', 'rb') as f:
        idx = f.read()
    with open(base + '.bzs', 'rb') as f:
        blocks = f.read()
    data = open(base + '.bzz', 'rb').read()
    cache = {}

    def block(n):
        if n not in cache:
            off, csize, _ = struct.unpack_from('<III', blocks, n * 12)
            cache.clear()                            # one block at a time is plenty
            cache[n] = zlib.decompress(data[off:off + csize])
        return cache[n]

    layout = next(((n, f) for n, f in ((10, '<IIH'), (12, '<III')) if len(idx) // n == want), (10, '<IIH'))
    entries = []
    for i in range(len(idx) // layout[0]):
        b, off, size = struct.unpack_from(layout[1], idx, i * layout[0])
        entries.append(None if size == 0 else (b, off, size))

    def text(entry):
        b, off, size = entry
        return block(b)[off:off + size]
    return entries, text


def read_rawcom(base, want):
    """RawCom indexes an entry in 6 bytes, RawCom4 in 8; the count says which."""
    with open(base + '.vss', 'rb') as f:
        idx = f.read()
    data = open(base, 'rb').read()
    layout = next(((n, f) for n, f in ((6, '<IH'), (8, '<II')) if len(idx) // n == want), (6, '<IH'))
    entries = []
    for i in range(len(idx) // layout[0]):
        off, size = struct.unpack_from(layout[1], idx, i * layout[0])
        entries.append(None if size == 0 else (off, size))
    return entries, lambda e: data[e[0]:e[0] + e[1]]


def clean(raw):
    """SWORD entries carry OSIS or ThML markup; keep the words and the paragraphs."""
    s = raw.decode('utf-8', 'replace')
    s = re.sub(r'<(?:title|reference|note|milestone|lb|divineName|hi|seg|q|w|transChange|p|div|br|font|scripRef|sync|a)\b[^>]*/?>',
               lambda m: '\n\n' if re.match(r'<(?:p|div|lb|br|title)\b', m.group(0)) else '', s, flags=re.I)
    s = re.sub(r'</(?:title|reference|note|divineName|hi|seg|q|w|transChange|p|div|font|scripRef|a)>',
               lambda m: '\n\n' if re.match(r'</(?:p|div|title)>', m.group(0)) else '', s, flags=re.I)
    s = re.sub(r'<[^>]+>', '', s)
    s = s.replace('&nbsp;', ' ').replace('&amp;', '&').replace('&lt;', '<').replace('&gt;', '>')
    paras = [re.sub(r'[ \t]+', ' ', p).strip() for p in re.split(r'\n\s*\n', s)]
    paras = [p for p in paras if p]
    # Some modules head each entry with its own key ("Rom.8.28", "Matt.5.1-Matt.5.2"); the app
    # shows the reference itself, so that line goes.
    ref = r'[1-4]?[A-Za-z]+\.\d+\.\d+'
    if paras and re.fullmatch(f'{ref}(?:\\s*-\\s*{ref})?', paras[0]):
        paras = paras[1:]
    return ''.join(f'<p>{p}</p>' for p in paras)


ap = argparse.ArgumentParser()
ap.add_argument('moduledir', help='the folder holding ot/nt (…/modules/comments/<kind>/<module>)')
ap.add_argument('versification')
ap.add_argument('out')
ap.add_argument('--name', default='')
args = ap.parse_args()

versification = json.load(open(args.versification))
rows = []
for part, books in (('ot', OT), ('nt', NT)):
    base = os.path.join(args.moduledir, part)
    keys = keys_for(books, versification)
    if os.path.exists(base + '.bzv'):
        entries, text = read_zcom(base, len(keys))
    elif os.path.exists(base + '.vss'):
        entries, text = read_rawcom(base, len(keys))
    else:
        continue
    if len(entries) < len(keys):
        print(f'! {part}: {len(entries)} entries for {len(keys)} keys — the module uses another '
              f'versification, so it is skipped', file=sys.stderr)
        continue
    # Verses that share one entry are collapsed into a single range.
    run = None
    for i, key in enumerate(keys):
        entry = entries[i] if i < len(entries) else None
        if key is None:
            continue
        if entry is None:
            # An empty slot carries on the comment before it: a section written on verses 1-21 is
            # stored once, against the first verse, with the rest left blank.
            if run and run['book'] == key[0] and run['chapter'] == key[1]:
                run['to'] = key[2]
            continue
        if run and run['entry'] == entry and run['book'] == key[0] and run['chapter'] == key[1]:
            run['to'] = key[2]
            continue
        if run:
            rows.append(run)
        run = {'entry': entry, 'book': key[0], 'chapter': key[1], 'from': key[2], 'to': key[2],
               'text': text(entry)}
    if run:
        rows.append(run)

db = sqlite3.connect(args.out)
db.executescript("""
DROP TABLE IF EXISTS info; DROP TABLE IF EXISTS commentaries;
CREATE TABLE info (name text, value text);
CREATE TABLE commentaries (book_number numeric, chapter_number_from numeric,
  verse_number_from numeric, chapter_number_to numeric, verse_number_to numeric, text text);
""")
db.execute('INSERT INTO info VALUES (?, ?)', ('description', args.name or os.path.basename(args.moduledir)))

# A module sometimes links one housekeeping note ("this module covers the New Testament only")
# to every verse of a testament. Anything repeated across more than a handful of books is
# furniture, not commentary.
for r in rows:
    r['html'] = clean(r['text'])
seen = {}
for r in rows:
    seen.setdefault(r['html'], set()).add(r['book'])
furniture = {html for html, books in seen.items() if len(books) > 8}

written = 0
for r in rows:
    html = r['html']
    if not html or html in furniture:
        continue
    db.execute('INSERT INTO commentaries VALUES (?, ?, ?, ?, ?, ?)',
               (MYBIBLE[r['book']], r['chapter'], r['from'], r['chapter'], r['to'], html))
    written += 1
db.commit()
print(f'{written} entries written to {args.out}')
