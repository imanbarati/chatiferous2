<?php
// Turns what someone typed into text + entities, the way Telegram's composer does:
//   **bold**  __italic__  *italic*  _italic_  ~~strike~~  `code`  ```pre```  ||spoiler||
//   > quote  [text](url)
// plus automatic links, emails, #hashtags and @mentions.
// Work is done on an array of characters (code points); offsets are converted to
// UTF-16 at the end, which is what stored entities and the browser use.

const MAX_MESSAGE_CHARS = 4096;

function compose_message(string $input, array $name_mentions = []): array
{
    $cps = mb_str_split(str_replace(["\r\n", "\r"], "\n", $input));
    $ents = [];

    md_pairs($cps, $ents, '```', 'pre', true);
    md_pairs($cps, $ents, '`', 'code', false);
    md_links($cps, $ents);
    foreach (['**' => 'bold', '__' => 'italic', '~~' => 'strike', '||' => 'spoiler'] as $marker => $type) {
        md_pairs($cps, $ents, $marker, $type, false);
    }
    // People write plain Markdown too, so a single *…* or _…_ is italic as well, but only where
    // it plainly means emphasis: the marks must sit against the words and outside them must be a
    // space or punctuation. That leaves snake_case_words, 2 * 3 and file_name.txt alone.
    md_single($cps, $ents, '*', 'italic');
    md_single($cps, $ents, '_', 'italic');
    md_quotes($cps, $ents);
    md_name_mentions($cps, $ents, $name_mentions);
    md_trim($cps, $ents);
    md_autodetect($cps, $ents);

    return [implode('', $cps), to_utf16($cps, $ents)];
}

function md_match(array $cps, int $i, array $marker): bool
{
    foreach ($marker as $k => $ch) {
        if (($cps[$i + $k] ?? null) !== $ch) {
            return false;
        }
    }
    return true;
}

function md_protected(array $ents, int $i): bool
{
    foreach ($ents as $e) {
        if (in_array($e['type'], ['pre', 'code'], true) && $i >= $e['offset'] && $i < $e['offset'] + $e['length']) {
            return true;
        }
    }
    return false;
}

// Removes $len characters at $pos and moves entities to match.
function md_remove(array &$cps, array &$ents, int $pos, int $len): void
{
    array_splice($cps, $pos, $len);
    $map = fn(int $x) => $x <= $pos ? $x : ($x >= $pos + $len ? $x - $len : $pos);
    foreach ($ents as $k => $e) {
        $start = $map($e['offset']);
        $end = $map($e['offset'] + $e['length']);
        if ($end <= $start) {
            unset($ents[$k]);
            continue;
        }
        $ents[$k]['offset'] = $start;
        $ents[$k]['length'] = $end - $start;
    }
    $ents = array_values($ents);
}

// A single *…* or _…_ around a word or phrase: italic, as most people expect from Markdown.
// Strict about its surroundings, so ordinary writing and code-ish words aren't touched.
function md_single(array &$cps, array &$ents, string $mark, string $type): void
{
    $outside = fn(?string $c) => $c === null || preg_match('/[\s\p{P}]/u', $c) === 1;
    for ($i = 0; $i < count($cps); $i++) {
        if ($cps[$i] !== $mark || md_protected($ents, $i)) {
            continue;
        }
        // Not next to another of the same mark: **bold** and __italic__ have had their turn.
        if (($cps[$i + 1] ?? '') === $mark || ($cps[$i - 1] ?? '') === $mark) {
            continue;
        }
        if (!$outside($cps[$i - 1] ?? null) || ($cps[$i + 1] ?? '') === '' || ctype_space($cps[$i + 1] ?? ' ')) {
            continue;
        }
        for ($j = $i + 2; $j < count($cps); $j++) {
            if ($cps[$j] === "\n") {
                break;                      // emphasis doesn't run past the end of a line
            }
            if ($cps[$j] !== $mark || md_protected($ents, $j)) {
                continue;
            }
            if (($cps[$j + 1] ?? '') === $mark) {
                break;
            }
            if (ctype_space($cps[$j - 1]) || !$outside($cps[$j + 1] ?? null)) {
                continue;               // "a * b" or "snake_case_word": not emphasis
            }
            md_remove($cps, $ents, $j, 1);
            md_remove($cps, $ents, $i, 1);
            $ents[] = ['type' => $type, 'offset' => $i, 'length' => $j - $i - 1];
            $i = $j - 2;
            break;
        }
    }
}

// Paired markers like **bold**. Inline ones stay on one line and can't start or
// end with a space (so "2 ** 3 ** 4" is left alone).
function md_pairs(array &$cps, array &$ents, string $marker, string $type, bool $block): void
{
    $m = mb_str_split($marker);
    $n = count($m);
    for ($i = 0; $i < count($cps); $i++) {
        if (!md_match($cps, $i, $m) || md_protected($ents, $i)) {
            continue;
        }
        $start = $i + $n;
        $close = null;
        for ($j = $start + 1; $j <= count($cps) - $n; $j++) {
            if (!$block && $cps[$j - 1] === "\n") {
                break;
            }
            if (md_match($cps, $j, $m) && !md_protected($ents, $j)) {
                $close = $j;
                break;
            }
        }
        if ($close === null) {
            continue;
        }
        if (!$block && (ctype_space($cps[$start]) || ctype_space($cps[$close - 1]))) {
            continue;
        }
        $language = null;
        $skip_open = $n;
        $end_trim = 0;
        if ($block) {
            // ```lang⏎ … ⏎``` : optional language on the first line, and the
            // newlines just inside the fences aren't part of the code.
            $nl = array_search("\n", array_slice($cps, $start, $close - $start), true);
            if ($nl !== false) {
                $first = implode('', array_slice($cps, $start, $nl));
                if (preg_match('/^[A-Za-z0-9+#-]{1,20}$/', $first)) {
                    $language = $first;
                    $skip_open += $nl + 1;
                } elseif ($first === '') {
                    $skip_open += 1;
                }
            }
            if ($close - 1 >= $i + $skip_open && $cps[$close - 1] === "\n") {
                $end_trim = 1;
            }
        }
        $content = $close - ($i + $skip_open) - $end_trim;
        if ($content <= 0) {
            continue;
        }
        md_remove($cps, $ents, $close - $end_trim, $end_trim + $n);
        md_remove($cps, $ents, $i, $skip_open);
        $e = ['type' => $type, 'offset' => $i, 'length' => $content];
        if ($language) {
            $e['language'] = $language;
        }
        $ents[] = $e;
        $i += $content - 1;
    }
}

// [text](https://…)
function md_links(array &$cps, array &$ents): void
{
    $text = implode('', $cps);
    if (!str_contains($text, '](')) {
        return;
    }
    for ($i = 0; $i < count($cps); $i++) {
        if ($cps[$i] !== '[' || md_protected($ents, $i)) {
            continue;
        }
        $close = null;
        for ($j = $i + 1; $j < count($cps) && $cps[$j] !== "\n"; $j++) {
            if ($cps[$j] === ']') {
                $close = $j;
                break;
            }
        }
        if ($close === null || $close === $i + 1 || ($cps[$close + 1] ?? '') !== '(') {
            continue;
        }
        $end = null;
        for ($k = $close + 2; $k < count($cps) && !ctype_space($cps[$k]); $k++) {
            if ($cps[$k] === ')') {
                $end = $k;
                break;
            }
        }
        if ($end === null) {
            continue;
        }
        $url = safe_url(implode('', array_slice($cps, $close + 2, $end - $close - 2)));
        if ($url === null) {
            continue;
        }
        $len = $close - $i - 1;
        md_remove($cps, $ents, $close, $end - $close + 1);
        md_remove($cps, $ents, $i, 1);
        $ents[] = ['type' => 'text_link', 'offset' => $i, 'length' => $len, 'url' => $url];
        $i += $len - 1;
    }
}

function safe_url(string $url): ?string
{
    $url = trim($url);
    if (preg_match('~^(https?://|mailto:)\S+$~i', $url)) {
        return $url;
    }
    // A path inside this app (…/bible/reading/2026-09-20). Nothing else relative is allowed, so
    // there is no way to smuggle a scheme in.
    if (str_starts_with($url, url()) && !str_contains($url, '//') && !str_contains($url, ':')) {
        return $url;
    }
    if (preg_match('~^[\w-]+(\.[\w-]+)+(/\S*)?$~', $url)) {
        return 'https://' . $url;
    }
    return null;
}

// Lines starting with "> " become one blockquote per run of lines.
function md_quotes(array &$cps, array &$ents): void
{
    $runs = [];
    $i = 0;
    while ($i < count($cps)) {
        $line_start = $i;
        if (($cps[$i] ?? '') === '>' && !md_protected($ents, $i)) {
            md_remove($cps, $ents, $i, ($cps[$i + 1] ?? '') === ' ' ? 2 : 1);
            $end = $line_start;
            while ($end < count($cps) && $cps[$end] !== "\n") {
                $end++;
            }
            $last = end($runs);
            if ($last && $last[1] === $line_start - 1) {
                $runs[count($runs) - 1][1] = $end;   // continues the previous quote
            } else {
                $runs[] = [$line_start, $end];
            }
            $i = $end + 1;
            continue;
        }
        while ($i < count($cps) && $cps[$i] !== "\n") {
            $i++;
        }
        $i++;
    }
    foreach ($runs as [$s, $e]) {
        if ($e > $s) {
            $ents[] = ['type' => 'blockquote', 'offset' => $s, 'length' => $e - $s];
        }
    }
}

// People picked from the @ list who have no username: "@Name" becomes "Name"
// linked to that member.
function md_name_mentions(array &$cps, array &$ents, array $mentions): void
{
    foreach ($mentions as $m) {
        $name = mb_str_split('@' . $m['name']);
        for ($i = 0; $i <= count($cps) - count($name); $i++) {
            if (md_match($cps, $i, $name) && !md_protected($ents, $i)) {
                md_remove($cps, $ents, $i, 1);
                $ents[] = ['type' => 'mention_name', 'offset' => $i, 'length' => count($name) - 1, 'user_id' => (int)$m['user_id']];
            }
        }
    }
}

function md_trim(array &$cps, array &$ents): void
{
    $lead = 0;
    while ($lead < count($cps) && ctype_space($cps[$lead])) {
        $lead++;
    }
    if ($lead) {
        md_remove($cps, $ents, 0, $lead);
    }
    $end = count($cps);
    while ($end > 0 && ctype_space($cps[$end - 1])) {
        $end--;
    }
    if ($end < count($cps)) {
        md_remove($cps, $ents, $end, count($cps) - $end);
    }
}

// Links, emails, #hashtags and @usernames, except inside code and existing links.
function md_autodetect(array $cps, array &$ents): void
{
    $text = implode('', $cps);
    $taken = function (int $s, int $len) use (&$ents): bool {
        foreach ($ents as $e) {
            if (in_array($e['type'], ['pre', 'code', 'text_link', 'url', 'email', 'mention', 'mention_name', 'hashtag'], true)
                && $s < $e['offset'] + $e['length'] && $s + $len > $e['offset']) {
                return true;
            }
        }
        return false;
    };
    $add = function (string $pattern, string $type, ?callable $extra = null) use ($text, $taken, &$ents) {
        if (!preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
            return;
        }
        foreach ($found[0] as [$match, $byte]) {
            $s = mb_strlen(substr($text, 0, $byte));
            $len = mb_strlen($match);
            if ($taken($s, $len)) {
                continue;
            }
            $e = ['type' => $type, 'offset' => $s, 'length' => $len];
            if ($extra) {
                $e = $extra($e, $match);
                if ($e === null) {
                    continue;
                }
            }
            $ents[] = $e;
        }
    };
    $add('~\b(?:https?://|www\.)[^\s<>"]+[^\s<>".,;:!?)\]\'"]~iu', 'url');
    $add('~\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b~u', 'email');
    $add('~(?<![\w#])#[\p{L}\p{N}_]{2,64}~u', 'hashtag');
    $add('~(?<![\w@])@[A-Za-z][A-Za-z0-9_]{2,31}\b~', 'mention', function (array $e, string $match) {
        $id = q("SELECT id FROM users WHERE username = ? AND status = 'active'", [substr($match, 1)])->fetchColumn();
        return $id ? $e + ['user_id' => (int)$id] : null;
    });
}

// Character offsets -> UTF-16 offsets (characters beyond U+FFFF count as two).
function to_utf16(array $cps, array $ents): array
{
    $pos = [0];
    foreach ($cps as $i => $ch) {
        $pos[$i + 1] = $pos[$i] + (mb_ord($ch) > 0xFFFF ? 2 : 1);
    }
    $out = [];
    foreach ($ents as $e) {
        $start = $pos[$e['offset']];
        $e['length'] = $pos[$e['offset'] + $e['length']] - $start;
        $e['offset'] = $start;
        $out[] = $e;
    }
    usort($out, fn($a, $b) => $a['offset'] <=> $b['offset'] ?: $b['length'] <=> $a['length']);
    return $out;
}

// Members mentioned in a message's entities.
function mentioned_users(array $ents): array
{
    $ids = [];
    foreach ($ents as $e) {
        if (!empty($e['user_id'])) {
            $ids[(int)$e['user_id']] = true;
        }
    }
    return array_keys($ids);
}
