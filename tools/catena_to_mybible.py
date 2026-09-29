#!/usr/bin/env python3
"""Turn the Catena Aurea (OSIS XML) into a MyBible file the app's importer reads.

    python3 tools/catena_to_mybible.py catena.xml catena.SQLite3

The catena is Aquinas's chain of patristic comment on the four gospels, in Newman's translation
of 1842. The OSIS edition marks each section with the verses it covers and names every father
quoted, which is what makes it safe to carry: the translation is named and long out of copyright,
unlike the modern compilations that circulate under the same title.

Source: https://github.com/Isidore-Guild/catena (CC0).
"""
import argparse
import re
import sqlite3

import warnings

from bs4 import BeautifulSoup     # the file has a few unclosed tags, so parse it forgivingly
from bs4 import XMLParsedAsHTMLWarning

warnings.filterwarnings('ignore', category=XMLParsedAsHTMLWarning)

BOOKS = {'Matt': 470, 'Mark': 480, 'Luke': 490, 'John': 500}
REF = re.compile(r'^\s*(Matt|Mark|Luke|John)\.\s*(\d+)\.\s*(\d+)')


def parse_ref(ref):
    """"Matt.5.1-Matt.5.3" -> (470, 5, 1, 3)"""
    first, _, last = ref.partition('-')
    m = REF.match(first)
    if not m:
        return None
    book, chapter, verse = BOOKS[m.group(1)], int(m.group(2)), int(m.group(3))
    end = REF.match(last) if last else None
    return book, chapter, verse, int(end.group(3)) if end and int(end.group(2)) == chapter else verse


def to_html(section):
    """The section's paragraphs, with the father's name in bold and quotations in italic.

    The paragraph carrying an osisID is the passage itself, which the reader already has on
    screen, so it is left out.
    """
    out = []
    for p in section.find_all('p', recursive=False) or section.find_all('p'):
        if p.get('osisid') or p.get('osisID'):
            continue
        bits = []
        for part in p.children:
            if getattr(part, 'name', None) == 'hi':
                text = part.get_text(' ', strip=True)
                bits.append(f'<strong>{text}</strong>' if part.get('type') == 'bold' else f'<em>{text}</em>')
            elif getattr(part, 'name', None):
                bits.append(part.get_text(' ', strip=True))
            else:
                bits.append(str(part))
        html = re.sub(r'\s+', ' ', ''.join(bits)).strip()
        if html:
            out.append(html)
    return ''.join(f'<p>{h}</p>' for h in out)


ap = argparse.ArgumentParser()
ap.add_argument('xml')
ap.add_argument('out')
args = ap.parse_args()

soup = BeautifulSoup(open(args.xml, encoding='utf-8').read(), 'html.parser')
rows = []
for div in soup.find_all('div'):
    if (div.get('annotatetype') or div.get('annotateType')) != 'commentary':
        continue
    place = parse_ref(div.get('annotateref') or div.get('annotateRef') or '')
    html = to_html(div)
    if place and html:
        book, chapter, verse, end = place
        rows.append((book, chapter, verse, chapter, end, html))

db = sqlite3.connect(args.out)
db.executescript("""
DROP TABLE IF EXISTS info; DROP TABLE IF EXISTS commentaries;
CREATE TABLE info (name text, value text);
CREATE TABLE commentaries (book_number numeric, chapter_number_from numeric,
  verse_number_from numeric, chapter_number_to numeric, verse_number_to numeric, text text);
""")
db.execute('INSERT INTO info VALUES (?, ?)',
           ('description', 'Catena Aurea (Aquinas), tr. J. H. Newman, 1842'))
db.executemany('INSERT INTO commentaries VALUES (?, ?, ?, ?, ?, ?)', rows)
db.commit()
print(f'{len(rows)} sections written to {args.out}')
