#!/usr/bin/env python3
"""Draws the app's home-screen icon.

    python3 tools/make_app_icon.py <design> <out dir>

Designs: leather (gilt book on oxblood), lamp (Psalm 119:105), parchment (ink book on cream),
tablet (gilt tables of the law on indigo). Writes icon-512, icon-192, apple-touch-icon (180)
and favicon-32 into the folder given, which is what config's app_icons points at.

The icon is drawn full-bleed: phones mask it to their own shape.
"""
import argparse
import math
import os

import cairo

SIZE = 512


def rounded(cr, x, y, w, h, r):
    cr.new_sub_path()
    cr.arc(x + w - r, y + r, r, -math.pi / 2, 0)
    cr.arc(x + w - r, y + h - r, r, 0, math.pi / 2)
    cr.arc(x + r, y + h - r, r, math.pi / 2, math.pi)
    cr.arc(x + r, y + r, r, math.pi, 3 * math.pi / 2)
    cr.close_path()


def field(cr, top, bottom):
    g = cairo.LinearGradient(0, 0, SIZE * 0.35, SIZE)
    g.add_color_stop_rgb(0, *top)
    g.add_color_stop_rgb(1, *bottom)
    cr.set_source(g)
    cr.paint()


def gold(cr, x0, y0, x1, y1):
    """The warm, slightly uneven sheen of stamped gilt."""
    g = cairo.LinearGradient(x0, y0, x1, y1)
    g.add_color_stop_rgb(0.00, 0.99, 0.90, 0.62)
    g.add_color_stop_rgb(0.35, 0.90, 0.74, 0.36)
    g.add_color_stop_rgb(0.62, 0.99, 0.91, 0.66)
    g.add_color_stop_rgb(1.00, 0.82, 0.64, 0.28)
    cr.set_source(g)


def open_book(cr, paint, spine_shadow=True):
    """An open book seen from above: two leaves rising from the spine."""
    cx, top, bottom = SIZE / 2, SIZE * 0.30, SIZE * 0.74
    reach = SIZE * 0.29
    for side in (-1, 1):
        cr.move_to(cx, top + SIZE * 0.035)
        cr.curve_to(cx + side * reach * 0.55, top - SIZE * 0.025,
                    cx + side * reach * 0.85, top + SIZE * 0.005,
                    cx + side * reach, top + SIZE * 0.045)
        cr.line_to(cx + side * reach, bottom - SIZE * 0.03)
        cr.curve_to(cx + side * reach * 0.85, bottom - SIZE * 0.075,
                    cx + side * reach * 0.55, bottom - SIZE * 0.10,
                    cx, bottom - SIZE * 0.045)
        cr.close_path()
        paint(cr)
        cr.fill()
    if spine_shadow:                      # the fold down the middle
        cr.set_source_rgba(0, 0, 0, 0.30)
        cr.set_line_width(SIZE * 0.018)
        cr.move_to(cx, top + SIZE * 0.035)
        cr.line_to(cx, bottom - SIZE * 0.045)
        cr.stroke()


def ribbon(cr, color):
    """A marker hanging from the middle of the book."""
    cx = SIZE / 2
    cr.set_source_rgb(*color)
    cr.move_to(cx - SIZE * 0.032, SIZE * 0.70)
    cr.line_to(cx + SIZE * 0.032, SIZE * 0.70)
    cr.line_to(cx + SIZE * 0.032, SIZE * 0.86)
    cr.line_to(cx, SIZE * 0.81)
    cr.line_to(cx - SIZE * 0.032, SIZE * 0.86)
    cr.close_path()
    cr.fill()


def design_leather(cr):
    field(cr, (0.42, 0.10, 0.13), (0.26, 0.05, 0.08))
    # a hairline gilt rule, as on a bound Bible
    cr.set_line_width(SIZE * 0.012)
    gold(cr, 0, 0, SIZE, SIZE)
    rounded(cr, SIZE * 0.085, SIZE * 0.085, SIZE * 0.83, SIZE * 0.83, SIZE * 0.10)
    cr.stroke()
    open_book(cr, lambda c: gold(c, SIZE * 0.2, SIZE * 0.25, SIZE * 0.8, SIZE * 0.8))


def design_lamp(cr):
    """Thy word is a lamp unto my feet: a flame over an open book.

    The ground is a deep navy rather than a black: among the topic tiles, which are bright, a
    near-black square reads as a hole.
    """
    field(cr, (0.16, 0.21, 0.45), (0.09, 0.12, 0.30))
    cx = SIZE / 2
    # the glow
    g = cairo.RadialGradient(cx, SIZE * 0.31, SIZE * 0.02, cx, SIZE * 0.31, SIZE * 0.30)
    g.add_color_stop_rgba(0, 1, 0.86, 0.55, 0.55)
    g.add_color_stop_rgba(1, 1, 0.80, 0.40, 0)
    cr.set_source(g)
    cr.paint()
    # the flame
    cr.move_to(cx, SIZE * 0.13)
    cr.curve_to(cx + SIZE * 0.10, SIZE * 0.26, cx + SIZE * 0.075, SIZE * 0.36,
                cx, SIZE * 0.40)
    cr.curve_to(cx - SIZE * 0.075, SIZE * 0.36, cx - SIZE * 0.10, SIZE * 0.26,
                cx, SIZE * 0.13)
    cr.close_path()
    g = cairo.LinearGradient(cx, SIZE * 0.13, cx, SIZE * 0.40)
    g.add_color_stop_rgb(0, 1.00, 0.95, 0.72)
    g.add_color_stop_rgb(1, 0.96, 0.66, 0.18)
    cr.set_source(g)
    cr.fill()
    # the book, lower and smaller, under the flame
    cr.save()
    cr.translate(0, SIZE * 0.16)
    cr.scale(1, 0.82)
    open_book(cr, lambda c: gold(c, SIZE * 0.2, SIZE * 0.3, SIZE * 0.8, SIZE * 0.85))
    cr.restore()


def design_parchment(cr):
    field(cr, (0.98, 0.95, 0.88), (0.90, 0.85, 0.74))
    open_book(cr, lambda c: c.set_source_rgb(0.16, 0.13, 0.11))
    ribbon(cr, (0.63, 0.13, 0.13))


def design_tablet(cr):
    """The book with a gilt cross above the spine."""
    field(cr, (0.11, 0.20, 0.35), (0.05, 0.10, 0.20))
    cx = SIZE / 2
    gold(cr, SIZE * 0.3, SIZE * 0.08, SIZE * 0.7, SIZE * 0.3)
    arm, thick = SIZE * 0.085, SIZE * 0.032
    cr.rectangle(cx - thick / 2, SIZE * 0.10, thick, arm * 2.1)
    cr.rectangle(cx - arm, SIZE * 0.155, arm * 2, thick)
    cr.fill()
    cr.save()
    cr.translate(0, SIZE * 0.07)
    cr.scale(1, 0.92)
    open_book(cr, lambda c: gold(c, SIZE * 0.2, SIZE * 0.3, SIZE * 0.8, SIZE * 0.85))
    cr.restore()


def design_word(cr):
    """The reader's own icon: the same midnight ground as the site's, the book without the flame."""
    field(cr, (0.09, 0.13, 0.29), (0.04, 0.06, 0.16))
    cr.save()
    cr.translate(0, SIZE * 0.02)
    cr.scale(1.06, 1.06)
    cr.translate(-SIZE * 0.03, -SIZE * 0.03)
    open_book(cr, lambda c: gold(c, SIZE * 0.2, SIZE * 0.25, SIZE * 0.8, SIZE * 0.8))
    cr.restore()
    ribbon(cr, (0.70, 0.16, 0.16))


DESIGNS = {'leather': design_leather, 'lamp': design_lamp, 'parchment': design_parchment,
           'tablet': design_tablet, 'word': design_word}

ap = argparse.ArgumentParser()
ap.add_argument('design', choices=sorted(DESIGNS))
ap.add_argument('outdir')
args = ap.parse_args()

surface = cairo.ImageSurface(cairo.FORMAT_ARGB32, SIZE, SIZE)
cr = cairo.Context(surface)
DESIGNS[args.design](cr)

os.makedirs(args.outdir, exist_ok=True)
master = os.path.join(args.outdir, 'icon-512.png')
surface.write_to_png(master)

from PIL import Image                                   # noqa: E402  (only needed for resizing)
big = Image.open(master)
for name, px in (('icon-192.png', 192), ('apple-touch-icon.png', 180), ('favicon-32.png', 32)):
    big.resize((px, px), Image.LANCZOS).save(os.path.join(args.outdir, name))
print('wrote', args.design, 'to', args.outdir)
