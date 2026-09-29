<?php
// Turns a USFM book file (the format Bible texts are published in) into what the reader needs:
// each chapter as finished HTML, each verse as plain text, and the footnotes and cross-references
// as rows of their own.
//
// Only the markers these public-domain texts actually use are handled; anything else is dropped
// rather than shown raw. The importer checks verse counts afterwards, so a marker we mishandle
// shows up as a missing verse rather than as silent damage.

// Paragraph markers → the class the reader styles them with.
const USFM_PARAGRAPHS = [
    'p' => 'p', 'm' => 'm', 'nb' => 'p', 'pi' => 'pi', 'pi1' => 'pi', 'pi2' => 'pi2', 'pc' => 'pc',
    'q' => 'q1', 'q1' => 'q1', 'q2' => 'q2', 'q3' => 'q3', 'q4' => 'q4', 'qr' => 'qr', 'qc' => 'qc',
    'li' => 'li1', 'li1' => 'li1', 'li2' => 'li2', 'ph' => 'pi', 'ph1' => 'pi', 'mi' => 'mi',
];
// Heading markers → tag and class.
const USFM_HEADINGS = [
    'ms' => ['h2', 'ms'], 'ms1' => ['h2', 'ms'], 'ms2' => ['h2', 'ms'],
    's' => ['h3', 's'], 's1' => ['h3', 's'], 's2' => ['h4', 's2'], 's3' => ['h4', 's2'], 's4' => ['h4', 's2'],
    'mr' => ['p', 'mr'], 'r' => ['p', 'r'], 'sr' => ['p', 'mr'], 'd' => ['p', 'd'], 'sp' => ['p', 'sp'],
];

// Parses one book. Returns:
//   ['code', 'name', 'abbrev', 'chapters' => [n => ['html', 'verses' => [v => text], 'notes' => [...]]]]
function usfm_parse_book(string $text): array
{
    $book = ['code' => '', 'name' => '', 'abbrev' => '', 'chapters' => []];
    $chapter = 0;
    $verse = 0;
    $html = '';          // the chapter being built
    $open = null;        // the open paragraph's class, or null
    $verses = [];        // verse => plain text, for the chapter
    $span = false;       // is a verse's <span> open right now?
    $notes = [];         // the chapter's footnotes and cross-references
    $letters = 0;        // how many notes so far in this chapter (for the a, b, c… markers)

    $closeParagraph = function () use (&$html, &$open, &$span) {
        if ($open !== null) {
            $html .= $span ? "</span></p>\n" : "</p>\n";
            $open = null;
            $span = false;
        }
    };
    $finishChapter = function () use (&$book, &$chapter, &$html, &$verses, &$notes, $closeParagraph) {
        if (!$chapter) {
            return;
        }
        $closeParagraph();
        $book['chapters'][$chapter] = ['html' => $html, 'verses' => $verses, 'notes' => $notes];
        $html = '';
        $verses = [];
        $notes = [];
    };

    // A paragraph marker and a verse often share a line ("\q1 \v 17 …"): split them, so each is
    // handled on its own.
    $text = preg_replace('/^(\\\\[a-z0-9]+)[ \t]+(\\\\v[ \t])/mu', "$1\n$2", $text);

    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '\\') {
            // A line continuing the previous one (USFM allows text to wrap).
            if ($line !== '' && $open !== null) {
                [$piece, $plain] = usfm_inline($line, $notes, $letters, $verse);
                if ($verse && !$span) {          // text after a paragraph break: reopen the verse
                    $html .= "<span class=\"v\" data-v=\"$verse\">";
                    $span = true;
                }
                $html .= ' ' . $piece;
                if ($verse) {
                    $verses[$verse] = trim(($verses[$verse] ?? '') . ' ' . $plain);
                }
            }
            continue;
        }
        preg_match('/^\\\\([a-z0-9]+)\*?\s?(.*)$/u', $line, $m);
        $marker = $m[1] ?? '';
        $rest = trim($m[2] ?? '');

        if ($marker === 'id') {
            $book['code'] = strtoupper(substr($rest, 0, 3));
        } elseif ($marker === 'h') {
            $book['name'] = $rest;
        } elseif ($marker === 'toc3' && $rest !== '') {
            $book['abbrev'] = $rest;
        } elseif ($marker === 'toc1' && $book['name'] === '') {
            $book['name'] = $rest;
        } elseif ($marker === 'c') {
            $finishChapter();
            $chapter = (int)$rest;
            $verse = 0;
            $letters = 0;
        } elseif (isset(USFM_HEADINGS[$marker])) {
            $closeParagraph();
            [$tag, $cls] = USFM_HEADINGS[$marker];
            [$piece] = usfm_inline($rest, $notes, $letters, $verse);
            $html .= "<$tag class=\"b-$cls\">$piece</$tag>\n";
        } elseif ($marker === 'b') {
            $closeParagraph();
            $html .= "<div class=\"b-b\"></div>\n";
        } elseif (isset(USFM_PARAGRAPHS[$marker])) {
            $closeParagraph();
            $open = USFM_PARAGRAPHS[$marker];
            $html .= "<p class=\"b-$open\">";
            if ($rest !== '') {
                [$piece, $plain] = usfm_inline($rest, $notes, $letters, $verse);
                if ($verse) {          // the verse carries on into this paragraph: no number again
                    $html .= "<span class=\"v\" data-v=\"$verse\">";
                    $span = true;
                    $verses[$verse] = trim(($verses[$verse] ?? '') . ' ' . $plain);
                }
                $html .= $piece;
            }
        } elseif ($marker === 'v') {
            if (!preg_match('/^(\d+)(?:-(\d+))?\s*(.*)$/u', $rest, $vm)) {
                continue;
            }
            if ($open === null) {          // a verse before any paragraph marker
                $open = 'p';
                $html .= "<p class=\"b-p\">";
            } elseif ($span) {
                $html .= '</span> ';
            }
            $verse = (int)$vm[1];
            $label = $vm[2] ? $vm[1] . '-' . $vm[2] : $vm[1];
            [$piece, $plain] = usfm_inline($vm[3], $notes, $letters, $verse);
            // The number is hidden from screen readers and "speak the screen": it isn't text to hear.
            $html .= "<span class=\"v\" data-v=\"$verse\">"
                . "<sup class=\"vn\" data-v=\"$verse\" aria-hidden=\"true\">$label</sup>$piece";
            $span = true;
            $verses[$verse] = trim($plain);
        }
        // Everything else (\ide, \mt, \usfm, \cl, \cp, \rem, \toc2…) is skipped on purpose.
    }
    $finishChapter();
    if ($book['abbrev'] === '') {
        $book['abbrev'] = mb_substr($book['name'], 0, 3);
    }
    return $book;
}

// One line of text: character markers become HTML, footnotes and cross-references are pulled out
// into $notes (as a side effect) and leave a marker behind. Returns [html, plain text].
function usfm_inline(string $s, array &$notes, int &$letters, int $verse): array
{
    // Footnotes \f … \f* and cross-references \x … \x*, innermost first.
    $s = preg_replace_callback('/\\\\([fx])\s*[+\-\s]?\s*(.*?)\\\\\1\*/us', function ($m) use (&$notes, &$letters, $verse) {
        $kind = $m[1];
        [$body, $refs] = usfm_note_body($m[2], $kind);
        if ($body === '' && $refs === '') {
            return '';
        }
        $marker = usfm_letter($letters++);
        $notes[] = ['verse' => $verse, 'kind' => $kind, 'marker' => $marker, 'body' => $body, 'refs' => $refs];
        $n = count($notes) - 1;
        $cls = $kind === 'f' ? 'fn' : 'xr';
        $sym = $kind === 'f' ? $marker : '✦';
        // Also hidden from screen readers: the note itself is read when it's opened.
        return "<sup class=\"$cls\" data-note=\"$n\" aria-hidden=\"true\">$sym</sup>";
    }, $s);

    // Character markers can be nested, and a nested one is written with a plus ("\+add of\+add*"),
    // which is how the King James text marks supplied words inside a quotation. Both forms count.
    $s = preg_replace('/\\\\\+?wj\s*(.*?)\\\\\+?wj\*/us', '<span class="wj">$1</span>', $s);
    $s = preg_replace('/\\\\\+?nd\s*(.*?)\\\\\+?nd\*/us', '<span class="nd">$1</span>', $s);
    // Words the translators supplied, which most Bibles set in italics.
    $s = preg_replace('/\\\\\+?add\s*(.*?)\\\\\+?add\*/us', '<em class="add">$1</em>', $s);
    $s = preg_replace('/\\\\\+?(?:it|em|bk|tl|qt|sc|pn|sig|ord|no|bd|bdit)\s*(.*?)\\\\\+?(?:it|em|bk|tl|qt|sc|pn|sig|ord|no|bd|bdit)\*/us', '<em>$1</em>', $s);
    // Strong's numbers: keep the word, drop the number.
    $s = preg_replace('/\\\\\+?w\s*(.*?)(?:\|[^\\\\]*)?\\\\\+?w\*/us', '$1', $s);
    // Anything left that we don't render: drop the marker, keep any text.
    $s = preg_replace('/\\\\\+?[a-z0-9]+\*?\s?/u', '', $s);

    $html = trim(preg_replace('/\s+/u', ' ', $s));
    // The Literal Standard Version marks a new line of poetry inside a verse with || — it sets its
    // poetry in ordinary paragraphs and leans on that instead of USFM's \q lines. Shown as written
    // it is a row of pipes through the Psalms, so it becomes the line break it stands for.
    $html = preg_replace('/\s*\|\|\s*/u', '<br>', $html);
    // The plain text is for search and for quoting: note markers are not part of the verse, and a
    // line break is a space.
    $plain = preg_replace('/<sup class="(?:fn|xr)"[^>]*>.*?<\/sup>/us', '', $html);
    $plain = str_replace('<br>', ' ', $plain);
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($plain)));
    return [$html, $plain];
}

// The readable part of a footnote or cross-reference: its own markers stripped, the verse
// reference it starts with (\fr, \xo) dropped, since the reader already knows where it is.
function usfm_note_body(string $s, string $kind): array
{
    $refs = '';
    if ($kind === 'x') {
        if (preg_match_all('/\\\\xt\s*(.*?)(?=\\\\|$)/us', $s, $m)) {
            $refs = trim(preg_replace('/\s+/u', ' ', implode('; ', $m[1])));
        }
    }
    $s = preg_replace('/\\\\(?:fr|xo)\s*\S+\s*/u', '', $s);            // "1.1" and the like
    $s = preg_replace('/\\\\\+?w\s*(.*?)(?:\|[^\\\\]*)?\\\\\+?w\*/us', '$1', $s);
    // Quoted words inside a note: the paired form first, then any unpaired remainder.
    $s = preg_replace('/\\\\(fq|fqa|xq)\s*(.*?)\\\\\1\*/us', '<em>$2</em>', $s);
    $s = preg_replace('/\\\\(?:fq|fqa|xq)\s*(.*?)(?=\\\\|$)/us', '<em>$1</em>', $s);
    $s = preg_replace('/\\\\\+?[a-z0-9]+\*?\s?/u', '', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return [$s, rtrim($refs, ' ;')];
}

// Note markers run a, b, c… then aa, ab… for chapters with many notes.
function usfm_letter(int $n): string
{
    $out = '';
    do {
        $out = chr(97 + $n % 26) . $out;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);
    return $out;
}
