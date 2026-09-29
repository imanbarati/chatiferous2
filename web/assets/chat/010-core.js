  // Core: the app's state, small helpers, and the message formatter.
  //
  //   state   everything on screen: topics, the open topic (state.view), members, drafts.
  //   APP     settings from the server (index.php), e.g. base, me, admin, group, gifs.
  //   $ / $$  shorthand for querySelector / querySelectorAll.
  //   api()   POST/GET to api/*.php with the CSRF token; throws with the server's message.
  //
  // Text is rendered from (text, entities) pairs as Telegram does: the server sends plain text plus
  // a list of ranges (bold, link, mention…). Everything is escaped here; nothing is trusted.

  'use strict';

  const APP = JSON.parse(document.body.dataset.app);
  const $ = (sel, root = document) => root.querySelector(sel);
  const topicsEl = $('#topics');
  const chatEl = $('#chat');
  const GROUP_MS = 15 * 60 * 1000;        // consecutive messages group within 15 minutes
  const SYNC_OPEN_MS = 3000;               // polling while a topic is open
  const SYNC_LIST_MS = 8000;               // polling on the topic list

  const state = {
    topics: [],
    users: {},
    view: null,   // the open topic: {topic, messages, hasOlder, hasNewer, firstUnread, lastRead, pins, pinIdx}
    loading: false,
    readTimer: null,
    pendingRead: 0,
    cursor: 0,
    syncTimer: null,
    syncing: false,
    compose: null,     // {mode: 'reply'|'edit'|'forward', message}
    previews: new Map(),  // link URL -> preview card data, or null if there's none
    dms: [],              // your direct-message conversations (newest first)
    listMode: 'topics',   // what the left-hand list shows: 'topics' or 'dms'
    uploads: [],       // [{key, file, id, status, url}]
    mentionPicks: [],  // [{user_id, name}] for members picked from the @ list who have no username
    members: null,
  };

  // ---------- Helpers ----------

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Calls an api/*.php endpoint with the CSRF token. Returns the parsed reply, or throws with
  // the server's own message, which is written for members to read.
  async function api(path, params = {}, post = false) {
    const url = new URL(APP.base + 'api/' + path, location.origin);
    const opts = { credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf } };
    if (post) {
      opts.method = 'POST';
      opts.body = new URLSearchParams(params);
    } else {
      for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    }
    const res = await fetch(url, opts);
    if (res.status === 401) { location.href = APP.base + 'login.php'; throw new Error('signed out'); }
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Something went wrong.');
    return data;
  }

  const sameDay = (a, b) => a.toDateString() === b.toDateString();

  // Clock time on a message, in the reader's own format (12- or 24-hour).
  function timeOf(d) {
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  // Topic list: time today, weekday this week, else a short date.
  function listTime(iso) {
    const d = new Date(iso), now = new Date();
    if (sameDay(d, now)) return timeOf(d);
    if (now - d < 6 * 864e5) return d.toLocaleDateString([], { weekday: 'short' });
    if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    return d.toLocaleDateString([], { month: 'numeric', day: 'numeric', year: '2-digit' });
  }

  // Date chips: Today, Yesterday, weekday within a week, else the date.
  function chipDate(d) {
    const now = new Date();
    const yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
    if (sameDay(d, now)) return 'Today';
    if (sameDay(d, yesterday)) return 'Yesterday';
    if (now - d < 6 * 864e5) return d.toLocaleDateString([], { weekday: 'long' });
    const opts = { month: 'long', day: 'numeric' };
    if (d.getFullYear() !== now.getFullYear()) opts.year = 'numeric';
    return d.toLocaleDateString([], opts);
  }

  // A file's size in the largest unit that keeps it readable.
  function fileSize(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(0) + ' KB';
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  }

  function initials(name) {
    // First letters of the first two words that start with a letter or digit ("Claude (site builder)" -> "CS").
    const words = (name || '').trim().split(/\s+/).map((w) => w.replace(/^[^\p{L}\p{N}]+/u, '')).filter(Boolean);
    return words.slice(0, 2).map((w) => [...w][0]).join('').toUpperCase() || '?';
  }

  // Someone's picture, or a coloured circle with their initials when they haven't one.
  function avatar(userId, cls = '') {
    const u = state.users[userId] || { name: '?', color: 0 };
    if (u.photo) return `<img class="avatar ${cls}" src="${esc(u.photo)}" alt="" loading="lazy">`;
    return `<span class="avatar c${u.color % 7} ${cls}" aria-hidden="true">${esc(initials(u.name))}</span>`;
  }

  // A topic's leading image: # for General, its emoji, its chosen symbol, or its first letter.
  // A topic's picture: its symbol (topic-icons.svg) or first letter, in a rounded square tinted
  // with the topic's colour. The symbol takes a strong shade of the same colour, so it reads
  // against the tint at any size; the square and the symbol colours are set in chat.css.
  function topicIcon(t, cls = '') {
    if (t.general) return `<span class="ticon general ${cls}" aria-hidden="true">#</span>`;
    if (t.emoji) return `<span class="ticon emoji ${cls}" aria-hidden="true">${esc(t.emoji)}</span>`;
    const colour = `c${t.color % 6}`;
    const inner = t.icon && /^[a-z0-9-]+$/.test(t.icon)
      // A square has no tail to leave room for, so the symbol fills more of it than it could in
      // the old bubble: about four fifths, edge to edge.
      ? `<svg viewBox="0 0 24 24" aria-hidden="true"><use href="${APP.icons}#ti-${t.icon}" x="2.4" y="2.4" width="19.2" height="19.2"/></svg>`
      : `<span class="ticon-letter">${esc(([...t.title.trim()][0] || '?').toUpperCase())}</span>`;
    // The symbol is always white; the square carries the colour. For subjects with an obvious
    // colour (heart, tree, flame, crown…) chat.css gives that square its own hue.
    const named = t.icon && /^[a-z0-9-]+$/.test(t.icon) ? ` ti-${t.icon}` : '';
    return `<span class="ticon square ${colour}${named} ${cls}" aria-hidden="true">${inner}</span>`;
  }

  // ---------- Formatted text ----------
  // Messages are text plus entities with UTF-16 offsets (which is how JS strings index).

  const BLOCKS = new Set(['blockquote', 'pre']);
  const INLINE_ORDER = ['text_link', 'url', 'email', 'mention', 'mention_name', 'hashtag', 'bold', 'italic', 'underline', 'strike', 'spoiler', 'code'];

  // A link's address, only if it's safe to follow: http(s) and mailto, plus bare domains
  // (example.com) which get https://. Anything else (javascript:, data:…) is refused.
  function safeHref(url) {
    const u = url.trim();
    if (/^(https?:|mailto:)/i.test(u)) return u;
    if (/^[\w.-]+\.[a-z]{2,}(\/|$)/i.test(u)) return 'https://' + u;
    return null;
  }

  // The opening HTML tag for one formatting range (bold, link, mention…), with its text escaped.
  function openTag(e, text) {
    const whole = text.slice(e.offset, e.offset + e.length);
    switch (e.type) {
      case 'text_link': case 'url': {
        const href = safeHref(e.type === 'url' ? whole : (e.url || ''));
        return href ? [`<a href="${esc(href)}" target="_blank" rel="noopener noreferrer">`, '</a>'] : ['', ''];
      }
      case 'email': return [`<a href="mailto:${esc(whole)}">`, '</a>'];
      case 'mention': case 'mention_name': return ['<span class="mention">', '</span>'];
      case 'hashtag': return [`<span class="hashtag" role="link" tabindex="0" data-tag="${esc(whole)}" title="Search for ${esc(whole)}">`, '</span>'];
      case 'bold': return ['<strong>', '</strong>'];
      case 'italic': return ['<em>', '</em>'];
      case 'underline': return ['<u>', '</u>'];
      case 'strike': return ['<s>', '</s>'];
      case 'spoiler': return ['<span class="spoiler" tabindex="0">', '</span>'];
      case 'code': return ['<code>', '</code>'];
      default: return ['', ''];
    }
  }

  // One line's worth of text with the inline formatting applied. Ranges can overlap and nest, so
  // the text is cut at every boundary and rebuilt piece by piece.
  function inlineHtml(text, ents, from, to) {
    const inside = ents.filter((e) => !BLOCKS.has(e.type) && e.offset < to && e.offset + e.length > from);
    const cuts = new Set([from, to]);
    for (const e of inside) {
      cuts.add(Math.max(from, e.offset));
      cuts.add(Math.min(to, e.offset + e.length));
    }
    const points = [...cuts].sort((a, b) => a - b);
    let html = '';
    for (let i = 0; i < points.length - 1; i++) {
      const a = points[i], b = points[i + 1];
      let piece = esc(text.slice(a, b)).replace(/\n/g, '<br>');
      const active = inside.filter((e) => e.offset <= a && e.offset + e.length >= b)
        .sort((x, y) => INLINE_ORDER.indexOf(y.type) - INLINE_ORDER.indexOf(x.type));
      for (const e of active) {
        const [open, close] = openTag(e, text);
        piece = open + piece + close;
      }
      html += piece;
    }
    return html;
  }

  // Text plus its formatting ranges, as HTML. Block ranges (quotes, code blocks) are laid out
  // first, then each piece's inline formatting inside them.
  function formatText(text, ents = []) {
    const blocks = ents.filter((e) => BLOCKS.has(e.type)).sort((a, b) => a.offset - b.offset);
    let html = '', pos = 0;
    for (const b of blocks) {
      if (b.offset < pos) continue; // overlapping blocks: keep the first
      html += inlineHtml(text, ents, pos, b.offset);
      const inner = b.type === 'pre'
        ? `<pre><code>${esc(text.slice(b.offset, b.offset + b.length))}</code></pre>`
        : `<blockquote>${inlineHtml(text, ents, b.offset, b.offset + b.length)}</blockquote>`;
      html += inner;
      pos = b.offset + b.length;
    }
    return html + inlineHtml(text, ents, pos, text.length);
  }
