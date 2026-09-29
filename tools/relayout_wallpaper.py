#!/usr/bin/env python3
"""Evenly re-spaces the symbols in web/assets/wallpaper-1.svg.

The tile is 900x900 and repeats, so distances wrap around the edges. Every
symbol is pushed away from any neighbour that is too close until the spacing
is even; the two copies of the same symbol are kept at least TWIN_GAP apart.
Sizes and rotations are kept. The dots are then dropped into the emptiest spots.
The tile is written out as the 3x3 set of translated copies the page expects.

    python3 tools/relayout_wallpaper.py [seed]
"""
import math
import random
import re
import sys

PATH = 'web/assets/wallpaper-1.svg'
TILE = 900
TWIN_GAP = 380      # minimum distance between two copies of the same symbol
SPACING = 1.7       # neighbour distance, as a multiple of the two symbols' mean size (high enough that they must fill the tile)

random.seed(int(sys.argv[1]) if len(sys.argv) > 1 else 7)
s = open(PATH).read()
open_first = '<g transform="translate(-900 -900)">'
head = s[:s.index(open_first)]
first = s[s.index(open_first) + len(open_first):]
first = first[:first.index('</g>')]
tail = s[s.rindex('</g>'):]          # closes the outer stroke-style group, then </svg>

uses, dots = [], []
for m in re.finditer(r'<use href="#w-([a-z0-9-]+)" x="([-0-9.]+)" y="([-0-9.]+)" width="([0-9.]+)"[^>]*?(?:rotate\(([-0-9.]+))?[^>]*>', first):
    n, x, y, w, r = m.groups()
    w = float(w)
    uses.append(dict(n=n, x=(float(x) + w / 2) % TILE, y=(float(y) + w / 2) % TILE, w=w, r=float(r or 0)))
for m in re.finditer(r'<circle cx="([-0-9.]+)" cy="([-0-9.]+)" r="([0-9.]+)"', first):
    dots.append(dict(x=0.0, y=0.0, rad=float(m.group(3))))


def delta(a, b):
    """Shortest (dx, dy) from a to b on the wrapping tile."""
    half = TILE / 2
    return (b['x'] - a['x'] + half) % TILE - half, (b['y'] - a['y'] + half) % TILE - half


ROUNDS = 3000
for it in range(ROUNDS):
    step = 0.25 * (1 - it / ROUNDS) + 0.025
    for a in uses:
        fx = fy = 0.0
        for b in uses:
            if a is b:
                continue
            dx, dy = delta(a, b)
            dist = math.hypot(dx, dy) or 0.01
            want = (a['w'] + b['w']) / 2 * SPACING
            if a['n'] == b['n']:
                want = max(want, TWIN_GAP)
            if dist < want:
                f = (want - dist) / dist
                fx -= dx * f
                fy -= dy * f
        a['x'] = (a['x'] + fx * step) % TILE
        a['y'] = (a['y'] + fy * step) % TILE

placed = []
for d in dots:
    best = None
    for _ in range(400):
        c = dict(x=random.uniform(0, TILE), y=random.uniform(0, TILE))
        room = min([math.hypot(*delta(c, u)) - u['w'] / 2 for u in uses] + [math.hypot(*delta(c, q)) for q in placed])
        if best is None or room > best[0]:
            best = (room, c)
    d['x'], d['y'] = best[1]['x'], best[1]['y']
    placed.append(d)

gap = min(math.hypot(*delta(a, b)) for a in uses for b in uses if a is not b)
twins = min(math.hypot(*delta(a, b)) for a in uses for b in uses if a is not b and a['n'] == b['n'])
hole = max(min(math.hypot(*delta(dict(x=gx, y=gy), u)) - u['w'] / 2 for u in uses)
           for gx in range(0, TILE, 15) for gy in range(0, TILE, 15))
print(f'{len(uses)} symbols, {len(dots)} dots; closest pair {gap:.0f}px, closest twins {twins:.0f}px, largest empty radius {hole:.0f}px')


def copy(tx, ty):
    out = [f'<use href="#w-{u["n"]}" x="{u["x"] - u["w"] / 2:.1f}" y="{u["y"] - u["w"] / 2:.1f}" width="{u["w"]:.1f}" '
           f'height="{u["w"]:.1f}" transform="rotate({u["r"]:.1f} {u["x"]:.1f} {u["y"]:.1f})"/>' for u in uses]
    out += [f'<circle cx="{d["x"]:.1f}" cy="{d["y"]:.1f}" r="{d["rad"]:.1f}" fill="#000" stroke="none"/>' for d in dots]
    return f'<g transform="translate({tx} {ty})">' + ''.join(out) + '</g>'


body = ''.join(copy(tx, ty) for ty in (-TILE, 0, TILE) for tx in (-TILE, 0, TILE))
open(PATH, 'w').write(head + body + tail)
