#!/usr/bin/env python3
"""Fetch one commentary, whole Bible, into the corpus layout.

For the handful of works whose ready-made databases have had their scripture references
stripped out (Poole, Haydock, Hodge), where the chapter pages carry the complete text.

    python3 tools/fetch_one_commentary.py poole <corpus dir> [--books romans,james] [--delay 0.8]

Writes <corpus>/<book>/<chapter>/commentaries/<work>.md, the same shape the corpus already
uses, so cli/import_commentary.php loads it. A chapter already fetched is left alone, so this
can be stopped and started freely. One request at a time, with a pause between: this is
somebody else's server.
"""
import argparse
import os
import sys
import time

sys.path.insert(0, os.path.expanduser("~/SynologyDrive/Religion/Commentaries/tools"))
import requests
import fetch_commentaries as fc

BOOKS = [
    ("genesis", 50), ("exodus", 40), ("leviticus", 27), ("numbers", 36), ("deuteronomy", 34),
    ("joshua", 24), ("judges", 21), ("ruth", 4), ("1_samuel", 31), ("2_samuel", 24),
    ("1_kings", 22), ("2_kings", 25), ("1_chronicles", 29), ("2_chronicles", 36), ("ezra", 10),
    ("nehemiah", 13), ("esther", 10), ("job", 42), ("psalms", 150), ("proverbs", 31),
    ("ecclesiastes", 12), ("songs", 8), ("isaiah", 66), ("jeremiah", 52), ("lamentations", 5),
    ("ezekiel", 48), ("daniel", 12), ("hosea", 14), ("joel", 3), ("amos", 9), ("obadiah", 1),
    ("jonah", 4), ("micah", 7), ("nahum", 3), ("habakkuk", 3), ("zephaniah", 3), ("haggai", 2),
    ("zechariah", 14), ("malachi", 4), ("matthew", 28), ("mark", 16), ("luke", 24), ("john", 21),
    ("acts", 28), ("romans", 16), ("1_corinthians", 16), ("2_corinthians", 13), ("galatians", 6),
    ("ephesians", 6), ("philippians", 4), ("colossians", 4), ("1_thessalonians", 5),
    ("2_thessalonians", 3), ("1_timothy", 6), ("2_timothy", 4), ("titus", 3), ("philemon", 1),
    ("hebrews", 13), ("james", 5), ("1_peter", 5), ("2_peter", 3), ("1_john", 5), ("2_john", 1),
    ("3_john", 1), ("jude", 1), ("revelation", 22),
]

ap = argparse.ArgumentParser()
ap.add_argument("work")
ap.add_argument("corpus")
ap.add_argument("--books", default="", help="comma-separated book names; default every book")
ap.add_argument("--delay", type=float, default=0.8)
args = ap.parse_args()

only = [b.strip() for b in args.books.split(",") if b.strip()]
runner = {"hodge": fc.fetch_hodge, "catena": fc.fetch_catena, "kretzmann": fc.fetch_kretzmann}.get(
    args.work, fc.fetch)
display = fc.AUTHOR_DISPLAY.get(args.work, args.work.title())
session = requests.Session()

got = missing = skipped = 0
for book, chapters in BOOKS:
    if only and book not in only:
        continue
    for chapter in range(1, chapters + 1):
        out_dir = os.path.join(args.corpus, book, str(chapter), "commentaries")
        path = os.path.join(out_dir, f"{args.work}.md")
        alt = os.path.join(out_dir, f"{book}_{chapter}_{args.work}.md")
        if os.path.exists(path) or os.path.exists(alt):
            skipped += 1
            continue
        _, body, n, status = runner(args.work, book, str(chapter), session, args.delay)
        if not body:
            missing += 1
            continue
        os.makedirs(out_dir, exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            f.write(f"# {display} — {book.replace('_', ' ').title()} {chapter}\n\n{body}\n")
        got += 1
    print(f"{book}: {got} fetched, {missing} not there, {skipped} already here", flush=True)

print(f"\n{display}: {got} chapters fetched, {missing} not available, {skipped} already here.")
