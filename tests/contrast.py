#!/usr/bin/env python3
"""WCAG contrast check for every text/background pair, in both themes.
Reads the color settings from web/assets/app.css. Normal text needs 4.5:1;
large or bold UI text (names, badges, buttons) needs 3:1."""
import re, sys

css = open(__file__.rsplit('/', 2)[0] + '/web/assets/app.css').read()

def block(selector):
    body = css[css.index(selector):]
    body = body[body.index('{') + 1: body.index('}')]
    return dict(re.findall(r'--([\w-]+):\s*([^;]+);', body))

light = block(':root {')
dark = {**light, **block(':root[data-theme="dark"]')}

def rgba(v, theme):
    v = v.strip()
    if v.startswith('var('):
        return rgba(theme[v[6:-1]], theme)
    if v.startswith('#'):
        h = v[1:]
        if len(h) == 3: h = ''.join(c * 2 for c in h)
        return [int(h[i:i + 2], 16) for i in (0, 2, 4)] + [int(h[6:8], 16) / 255 if len(h) == 8 else 1]
    m = re.match(r'rgba?\(([^)]*)\)', v)
    parts = [float(x) for x in m.group(1).split(',')]
    return parts[:3] + [parts[3] if len(parts) > 3 else 1]

def over(top, bottom):  # alpha-composite
    a = top[3]
    return [top[i] * a + bottom[i] * (1 - a) for i in range(3)] + [1]

def lum(c):
    def ch(x):
        x /= 255
        return x / 12.92 if x <= 0.03928 else ((x + 0.055) / 1.055) ** 2.4
    return 0.2126 * ch(c[0]) + 0.7152 * ch(c[1]) + 0.0722 * ch(c[2])

def ratio(a, b):
    la, lb = sorted([lum(a), lum(b)], reverse=True)
    return (la + 0.05) / (lb + 0.05)

def wallpaper_stops(theme):
    return [rgba(x, theme) for x in re.findall(r'#[0-9a-fA-F]{6}', theme['wallpaper'])]

# (description, text, background, needed, [layer under the background])
PAIRS = [
    ('body text on panels', '--text', '--panel', 4.5),
    ('secondary text on panels', '--muted', '--panel', 4.5),
    ('secondary text on hover', '--muted', '--hover', 4.5),
    ('links on panels (pinned bar, reply bar)', '--link', '--panel', 4.5),
    ('text in incoming bubbles', '--text', '--bubble-in', 4.5),
    ('links/mentions in incoming bubbles', '--link', '--bubble-in', 4.5),
    ('time in incoming bubbles', '--meta', '--bubble-in', 4.5),
    ('text in own bubbles', '--text', '--bubble-out', 4.5),
    ('links in own bubbles', '--out-link', '--bubble-out', 4.5),
    ('time in own bubbles', '--out-meta', '--bubble-out', 4.5),
    ('reply name/poll accents in own bubbles', '--out-accent', '--bubble-out', 3),
    ('reaction count in own bubbles', '--out-accent', ('--out-soft', '--bubble-out'), 3),
    ('my reaction in own bubbles', '--bubble-out', '--out-accent', 3),
    ('reaction count in incoming bubbles', '--link', ('--accent-soft', '--bubble-in'), 3),
    ('code in bubbles', '--text', ('--code-bg', '--bubble-in'), 4.5),
    ('white on accent (buttons, badges)', '#ffffff', '--accent', 3),
    ('white on muted badge', '#ffffff', '--muted-badge', 3),
    ('error text', '--error-text', '--error-bg', 4.5),
    ('notice text', '--text', '--ok-bg', 4.5),
] + [(f'sender name color {i} in bubbles', f'--n{i}', '--bubble-in', 3) for i in range(7)]

def resolve(x, theme):
    return rgba(x if x.startswith('#') else f'var({x})', theme)

fails = 0
for name, theme in (('LIGHT', light), ('DARK', dark)):
    print(f'== {name}')
    rows = []
    for desc, fg, bg, need in PAIRS:
        if isinstance(bg, tuple):
            b = over(resolve(bg[0], theme), resolve(bg[1], theme))
        else:
            b = resolve(bg, theme)
        f = over(resolve(fg, theme), b)
        rows.append((desc, ratio(f, b), need))
    # White text on the see-through date/service chips, over the lightest wallpaper color.
    chip = resolve('--chip-bg', theme)
    worst = min(ratio([255, 255, 255, 1], over(chip, s)) for s in wallpaper_stops(theme))
    rows.append(('white text on date/service chips (worst spot)', worst, 4.5))
    unread = min(ratio(over(resolve('--link', theme), over(resolve('--unread-bar', theme), s)),
                       over(resolve('--unread-bar', theme), s)) for s in wallpaper_stops(theme))
    rows.append(('"Unread Messages" bar (worst spot)', unread, 4.5))
    # Bubbles must stand out from the wallpaper (a surface, not text: 1.5:1 is plenty visible).
    bub = min(ratio(resolve('--bubble-in', theme), s) for s in wallpaper_stops(theme)) if name == 'DARK' else None
    if bub is not None:
        rows.append(('incoming bubbles against the wallpaper (worst spot)', bub, 1.5))
    for desc, r, need in rows:
        ok = r >= need
        fails += not ok
        print(f"  {'ok  ' if ok else 'FAIL'} {r:5.2f} (needs {need}) {desc}")
print(f'\n{fails} failing pair(s)')
sys.exit(1 if fails else 0)
