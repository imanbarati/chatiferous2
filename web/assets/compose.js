// Turns what someone typed into text + formatting ("entities"), as app/lib/compose.php does on
// the server, for direct messages, which are sealed in the browser before sending (the server
// never sees them, so it can't do this).
//   **bold**  __italic__  *italic*  _italic_  ~~strike~~  `code`  ```pre```  ||spoiler||
//   > quote  [text](url)
// plus automatic links, emails, #hashtags and @mentions. Works on an array of characters
// (code points); offsets are converted to UTF-16 at the end, as stored entities use.
// Keep in step with compose.php.
(() => {
  const isSpace = (c) => c === ' ' || c === '\t' || c === '\n' || c === '\r' || c === '\v' || c === '\f';

  function match(cps, i, marker) {
    for (let k = 0; k < marker.length; k++) if (cps[i + k] !== marker[k]) return false;
    return true;
  }
  function isProtected(ents, i) {
    return ents.some((e) => (e.type === 'pre' || e.type === 'code') && i >= e.offset && i < e.offset + e.length);
  }
  // Removes len characters at pos and moves entities to match.
  function remove(cps, ents, pos, len) {
    cps.splice(pos, len);
    const map = (x) => (x <= pos ? x : x >= pos + len ? x - len : pos);
    for (let k = ents.length - 1; k >= 0; k--) {
      const start = map(ents[k].offset), end = map(ents[k].offset + ents[k].length);
      if (end <= start) { ents.splice(k, 1); continue; }
      ents[k].offset = start;
      ents[k].length = end - start;
    }
  }

  // A single *…* or _…_ is italic too, as plain Markdown does it, but only where it plainly means
  // emphasis: marks against the words, space or punctuation outside. Keeps snake_case_words,
  // 2 * 3 and file_name.txt out of it. Must match md_single() in lib/compose.php exactly.
  function single(cps, ents, mark, type) {
    const outside = (c) => c === undefined || /[\s\p{P}]/u.test(c);
    const space = (c) => c === undefined || /\s/.test(c);
    for (let i = 0; i < cps.length; i++) {
      if (cps[i] !== mark || isProtected(ents, i)) continue;
      if (cps[i + 1] === mark || cps[i - 1] === mark) continue;
      if (!outside(cps[i - 1]) || cps[i + 1] === undefined || space(cps[i + 1])) continue;
      for (let j = i + 2; j < cps.length; j++) {
        if (cps[j] === '\n') break;
        if (cps[j] !== mark || isProtected(ents, j)) continue;
        if (cps[j + 1] === mark) break;
        if (space(cps[j - 1]) || !outside(cps[j + 1])) continue;
        remove(cps, ents, j, 1);
        remove(cps, ents, i, 1);
        ents.push({ type, offset: i, length: j - i - 1 });
        i = j - 2;
        break;
      }
    }
  }

  function pairs(cps, ents, marker, type, block) {
    const m = [...marker], n = m.length;
    for (let i = 0; i < cps.length; i++) {
      if (!match(cps, i, m) || isProtected(ents, i)) continue;
      const start = i + n;
      let close = null;
      for (let j = start + 1; j <= cps.length - n; j++) {
        if (!block && cps[j - 1] === '\n') break;
        if (match(cps, j, m) && !isProtected(ents, j)) { close = j; break; }
      }
      if (close === null) continue;
      if (!block && (isSpace(cps[start]) || isSpace(cps[close - 1]))) continue;
      let language = null, skipOpen = n, endTrim = 0;
      if (block) {
        const nl = cps.slice(start, close).indexOf('\n');
        if (nl !== -1) {
          const first = cps.slice(start, start + nl).join('');
          if (/^[A-Za-z0-9+#-]{1,20}$/.test(first)) { language = first; skipOpen += nl + 1; } else if (first === '') skipOpen += 1;
        }
        if (close - 1 >= i + skipOpen && cps[close - 1] === '\n') endTrim = 1;
      }
      const content = close - (i + skipOpen) - endTrim;
      if (content <= 0) continue;
      remove(cps, ents, close - endTrim, endTrim + n);
      remove(cps, ents, i, skipOpen);
      const e = { type, offset: i, length: content };
      if (language) e.language = language;
      ents.push(e);
      i += content - 1;
    }
  }

  function safeUrl(url) {
    url = url.trim();
    if (/^(https?:\/\/|mailto:)\S+$/i.test(url)) return url;
    if (/^[\w-]+(\.[\w-]+)+(\/\S*)?$/.test(url)) return 'https://' + url;
    return null;
  }

  function links(cps, ents) {
    if (!cps.join('').includes('](')) return;
    for (let i = 0; i < cps.length; i++) {
      if (cps[i] !== '[' || isProtected(ents, i)) continue;
      let close = null;
      for (let j = i + 1; j < cps.length && cps[j] !== '\n'; j++) if (cps[j] === ']') { close = j; break; }
      if (close === null || close === i + 1 || cps[close + 1] !== '(') continue;
      let end = null;
      for (let k = close + 2; k < cps.length && !isSpace(cps[k]); k++) if (cps[k] === ')') { end = k; break; }
      if (end === null) continue;
      const url = safeUrl(cps.slice(close + 2, end).join(''));
      if (url === null) continue;
      const len = close - i - 1;
      remove(cps, ents, close, end - close + 1);
      remove(cps, ents, i, 1);
      ents.push({ type: 'text_link', offset: i, length: len, url });
      i += len - 1;
    }
  }

  function quotes(cps, ents) {
    const runs = [];
    let i = 0;
    while (i < cps.length) {
      const lineStart = i;
      if (cps[i] === '>' && !isProtected(ents, i)) {
        remove(cps, ents, i, cps[i + 1] === ' ' ? 2 : 1);
        let end = lineStart;
        while (end < cps.length && cps[end] !== '\n') end++;
        const last = runs[runs.length - 1];
        if (last && last[1] === lineStart - 1) last[1] = end; else runs.push([lineStart, end]);
        i = end + 1;
        continue;
      }
      while (i < cps.length && cps[i] !== '\n') i++;
      i++;
    }
    for (const [s, e] of runs) if (e > s) ents.push({ type: 'blockquote', offset: s, length: e - s });
  }

  // People picked from the @ list who have no username: "@Name" becomes "Name" linked to them.
  function nameMentions(cps, ents, picks) {
    for (const p of picks) {
      const name = [...('@' + p.name)];
      for (let i = 0; i <= cps.length - name.length; i++) {
        if (match(cps, i, name) && !isProtected(ents, i)) {
          remove(cps, ents, i, 1);
          ents.push({ type: 'mention_name', offset: i, length: name.length - 1, user_id: p.user_id });
        }
      }
    }
  }

  function trim(cps, ents) {
    let lead = 0;
    while (lead < cps.length && isSpace(cps[lead])) lead++;
    if (lead) remove(cps, ents, 0, lead);
    let end = cps.length;
    while (end > 0 && isSpace(cps[end - 1])) end--;
    if (end < cps.length) remove(cps, ents, end, cps.length - end);
  }

  // Links, emails, #hashtags and @usernames (known members only), except in code and links.
  function autodetect(cps, ents, usernames) {
    const text = cps.join('');
    // Character index of each UTF-16 position, to turn regex matches into character offsets.
    const idx = [];
    let ci = 0;
    for (const ch of cps) { idx.push(ci); if (ch.length === 2) idx.push(ci); ci++; }
    idx.push(ci);
    const blocking = ['pre', 'code', 'text_link', 'url', 'email', 'mention', 'mention_name', 'hashtag'];
    const taken = (s, len) => ents.some((e) => blocking.includes(e.type) && s < e.offset + e.length && s + len > e.offset);
    const add = (re, type, extra) => {
      for (const m of text.matchAll(re)) {
        const s = idx[m.index], len = idx[m.index + m[0].length] - s;
        if (taken(s, len)) continue;
        let e = { type, offset: s, length: len };
        if (extra) { e = extra(e, m[0]); if (!e) continue; }
        ents.push(e);
      }
    };
    add(/\b(?:https?:\/\/|www\.)[^\s<>"]+[^\s<>".,;:!?)\]'"]/giu, 'url');
    add(/\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b/gu, 'email');
    add(/(?<![\w#])#[\p{L}\p{N}_]{2,64}/gu, 'hashtag');
    add(/(?<![\w@])@[A-Za-z][A-Za-z0-9_]{2,31}\b/g, 'mention', (e, m) => {
      const id = usernames.get(m.slice(1).toLowerCase());
      return id ? { ...e, user_id: id } : null;
    });
  }

  function toUtf16(cps, ents) {
    const pos = [0];
    cps.forEach((ch, i) => { pos[i + 1] = pos[i] + ch.length; });
    return ents.map((e) => ({ ...e, offset: pos[e.offset], length: pos[e.offset + e.length] - pos[e.offset] }))
      .sort((a, b) => a.offset - b.offset || b.length - a.length);
  }

  // picks: [{user_id, name}] from the @ list; usernames: Map(lowercase username -> user id).
  function format(input, picks = [], usernames = new Map()) {
    const cps = [...String(input).replace(/\r\n?/g, '\n')];
    const ents = [];
    pairs(cps, ents, '```', 'pre', true);
    pairs(cps, ents, '`', 'code', false);
    links(cps, ents);
    for (const [marker, type] of [['**', 'bold'], ['__', 'italic'], ['~~', 'strike'], ['||', 'spoiler']]) pairs(cps, ents, marker, type, false);
    single(cps, ents, '*', 'italic');
    single(cps, ents, '_', 'italic');
    quotes(cps, ents);
    nameMentions(cps, ents, picks);
    trim(cps, ents);
    autodetect(cps, ents, usernames);
    return { text: cps.join(''), ents: toUtf16(cps, ents) };
  }

  self.Compose = { format, MAX_CHARS: 4096 };
})();
