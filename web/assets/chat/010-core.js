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
    let res;
    try {
      res = await fetch(url, opts);
    } catch (err) {
      missed();
      throw new NoSignal();
    }
    if (res.status === 401) { location.href = APP.base + 'login.php'; throw new Error('signed out'); }
    let data;
    try {
      data = await res.json();
    } catch (err) {
      // Something other than the app answered: the host's rate limiter, a gateway error, a proxy's
      // holding page. Reading an HTML page as JSON throws a parse error nobody can act on, so it
      // counts as having no signal — the member sees the copy on this device instead.
      missed();
      throw new NoSignal();
    }
    if (!res.ok) throw new Error(data.error || 'Something went wrong.');
    misses = 0;
    setOffline(false);
    return data;
  }

  // Not being able to reach the server, told apart from the server saying no. Callers fall back to
  // what this device has kept rather than showing an error for something nobody can fix.
  class NoSignal extends Error {
    constructor() {
      super('No connection.');
      this.name = 'NoSignal';
    }
  }

  // Whether the app can reach the server. Everything that should look or behave differently hangs
  // off the one body class, so there is a single answer to the question on screen at any moment.
  function setOffline(on) {
    on = !!on;
    if (document.body.classList.contains('is-offline') === on) return;
    document.body.classList.toggle('is-offline', on);
    offlineUi();
  }

  const offlineNow = () => document.body.classList.contains('is-offline');

  // One failed call is a hiccup: a rate limit, a dropped packet, a host having a moment. Announcing
  // "no connection" for that is worse than saying nothing, and it used to take the message box away
  // mid-sentence. Two failures in a row, or the browser saying so itself, is news worth breaking.
  let misses = 0;
  function missed() {
    misses++;
    if (!navigator.onLine || misses >= 2) setOffline(true);
  }

  // Says so, and stops the message box pretending it can send.
  //
  // The box itself is never disabled. Someone may well want to write their reply while they wait,
  // and taking the keyboard away mid-sentence throws the words away — which is what it did before
  // this was written down. Only sending is stopped, and nothing is queued: what was written waits
  // in the draft, where its author can see it, until there is a signal to send it over.
  function offlineUi() {
    const off = offlineNow();
    for (const ta of document.querySelectorAll('.composer textarea')) {
      if (ta.dataset.ph === undefined) ta.dataset.ph = ta.placeholder || '';
      ta.placeholder = off ? 'No connection — you can write, but not send yet' : ta.dataset.ph;
    }
    const send = $('.composer .send', chatEl);
    if (send && off) send.disabled = true;
    else if (send) updateSendButton();
    let note = $('#offline-note');
    if (off && !note) {
      note = document.createElement('div');
      note.id = 'offline-note';
      note.className = 'offline-note';
      note.textContent = 'No connection — showing what’s on this device.';
      document.body.append(note);
    } else if (!off && note) {
      note.remove();
    }
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

  // Someone's picture, or a colored circle with their initials when they haven't one.
  function avatar(userId, cls = '') {
    const u = state.users[userId] || { name: '?', color: 0 };
    if (u.photo) return `<img class="avatar ${cls}" src="${esc(u.photo)}" alt="" loading="lazy">`;
    return `<span class="avatar c${u.color % 7} ${cls}" aria-hidden="true">${esc(initials(u.name))}</span>`;
  }

  // A topic's leading image: # for General, its emoji, its chosen symbol, or its first letter.
  // A topic's picture: its symbol (topic-icons.svg) or first letter, in a rounded square tinted
  // with the topic's color. The symbol takes a strong shade of the same color, so it reads
  // against the tint at any size; the square and the symbol colors are set in chat.css.
  function topicIcon(t, cls = '') {
    if (t.general) return `<span class="ticon general ${cls}" aria-hidden="true">#</span>`;
    if (t.emoji) return `<span class="ticon emoji ${cls}" aria-hidden="true">${esc(t.emoji)}</span>`;
    const color = `c${t.color % 6}`;
    const inner = t.icon && /^[a-z0-9-]+$/.test(t.icon)
      // A square has no tail to leave room for, so the symbol fills more of it than it could in
      // the old bubble: about four fifths, edge to edge.
      ? `<svg viewBox="0 0 24 24" aria-hidden="true"><use href="${APP.icons}#ti-${t.icon}" x="2.4" y="2.4" width="19.2" height="19.2"/></svg>`
      : `<span class="ticon-letter">${esc(([...t.title.trim()][0] || '?').toUpperCase())}</span>`;
    // The symbol is always white; the square carries the color. For subjects with an obvious
    // color (heart, tree, flame, crown…) chat.css gives that square its own hue.
    const named = t.icon && /^[a-z0-9-]+$/.test(t.icon) ? ` ti-${t.icon}` : '';
    return `<span class="ticon square ${color}${named} ${cls}" aria-hidden="true">${inner}</span>`;
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
    // A path inside the app itself, as the daily reading uses to point at its own page.
    if (u.startsWith(APP.base) && !u.includes('//') && !u.includes(':')) return u;
    return null;
  }

  // The opening HTML tag for one formatting range (bold, link, mention…), with its text escaped.
  function openTag(e, text) {
    const whole = text.slice(e.offset, e.offset + e.length);
    switch (e.type) {
      case 'text_link': case 'url': {
        const href = safeHref(e.type === 'url' ? whole : (e.url || ''));
        if (!href) return ['', ''];
        // A link into the app itself opens in the app; anywhere else opens in a new tab.
        return href.startsWith(APP.base)
          ? [`<a href="${esc(href)}">`, '</a>']
          : [`<a href="${esc(href)}" target="_blank" rel="noopener noreferrer">`, '</a>'];
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
