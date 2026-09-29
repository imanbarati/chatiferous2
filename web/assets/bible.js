// The Bible reader's page: swiping between chapters, the book picker, footnotes, the version
// chooser, and the reading settings. The first chapter is rendered by bible.php; everything after
// that is fetched from api/bible.php, so reading on never reloads the page.
(() => {
  'use strict';
  const APP = JSON.parse(document.body.dataset.bible);
  const $ = (sel, el = document) => el.querySelector(sel);
  const page = $('#b-page');
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(APP.base + 'sw.php', { scope: APP.base }).catch(() => {});
  }

  // ---------- settings (kept on the device) ----------
  const DEFAULTS = {
    font: 'serif', size: 3, theme: 'app', red: 0, numbers: 1, notes: 'markers', plain: 0, xrefs: 1,
    // Whether the commentary covers the screen. It normally leaves the verse it comments on in
    // sight above it, which is the point of a sheet; but on a phone that is a quarter of the screen
    // given to words you have just read, and some would rather have it all.
    commfull: 0,
    // The commentary is set apart from the text it comments on: its own font and size, which
    // start out as the reader's own and go their own way once touched.
    cfont: '', csize: -1,
    // Which translations the search box looks in. Empty means "work it out from what's being read".
    sv: [],
    // The last search: what was asked, where, and how far down the answers you had got.
    find: null,
  };
  // What the account remembers wins over what this device remembers: a new phone then reads the
  // way the last one did.
  const load = () => {
    let local = {};
    try { local = JSON.parse(localStorage.getItem('bible-settings') || '{}'); } catch (e) { /* none */ }
    return { ...DEFAULTS, ...local, ...(APP.settings || {}) };
  };
  let S = load();
  let saveTimer = null;
  const saveSettings = () => fetch(`${APP.base}api/bible.php`, {
    method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf },
    body: new URLSearchParams({ action: 'prefs', settings: JSON.stringify(S) }),
  }).catch(() => {});
  const SIZES = ['0.95rem', '1.03rem', '1.12rem', '1.22rem', '1.35rem', '1.5rem', '1.7rem'];
  const LEADS = ['1.5', '1.55', '1.65', '1.7', '1.75', '1.8', '1.85'];

  function applySettings() {
    const b = document.body;
    b.classList.remove('b-font-serif', 'b-font-system', 'b-font-legible');
    b.classList.add('b-font-' + S.font);
    b.classList.toggle('b-paper', S.theme === 'paper');
    b.classList.toggle('b-red', !!S.red);
    b.classList.toggle('b-nonums', !S.numbers);
    b.classList.toggle('b-nonotes', S.notes === 'off');
    b.classList.toggle('b-notes-inline', S.notes === 'inline');
    b.classList.toggle('b-plain', !!S.plain);
    b.classList.toggle('b-noxrefs', !S.xrefs);
    b.classList.toggle('b-commfull', !!S.commfull);
    b.style.setProperty('--b-size', SIZES[S.size]);
    b.style.setProperty('--b-lead', LEADS[S.size]);
    const cfont = S.cfont || S.font;
    const csize = S.csize >= 0 ? S.csize : S.size;
    b.classList.remove('b-cfont-serif', 'b-cfont-system', 'b-cfont-legible');
    b.classList.add('b-cfont-' + cfont);
    b.style.setProperty('--c-size', SIZES[csize]);
    b.style.setProperty('--c-lead', LEADS[csize]);
    const label = (k, v) => { const el = $(`[data-val="${k}"]`); if (el) el.textContent = v; };
    label('font', { serif: 'Serif', system: 'System', legible: 'Hyperlegible' }[S.font]);
    label('theme', S.theme === 'paper' ? 'Paper' : 'App');
    label('red', S.red ? 'On' : 'Off');
    label('numbers', S.numbers ? 'On' : 'Off');
    label('notes', { markers: 'Markers', inline: 'In the text', off: 'Hidden' }[S.notes]);
    label('plain', S.plain ? 'On' : 'Off');
    label('xrefs', S.xrefs ? 'On' : 'Off');
    label('commfull', S.commfull ? 'Full screen' : 'With the text');
    try { localStorage.setItem('bible-settings', JSON.stringify(S)); } catch (e) { /* private window */ }
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveSettings, 800);   // and to the account, once the tapping stops
  }

  document.addEventListener('click', (e) => {
    const set = e.target.closest('[data-set]')?.dataset.set;
    const size = e.target.closest('[data-size]')?.dataset.size;
    if (size) {
      S.size = Math.max(0, Math.min(SIZES.length - 1, S.size + (+size)));
    } else if (set === 'font') {
      S.font = { serif: 'system', system: 'legible', legible: 'serif' }[S.font];
    } else if (set === 'theme') {
      S.theme = S.theme === 'paper' ? 'app' : 'paper';
    } else if (set === 'notes') {
      S.notes = { markers: 'inline', inline: 'off', off: 'markers' }[S.notes];
    } else if (set) {
      S[set] = S[set] ? 0 : 1;
    } else {
      return;
    }
    applySettings();
    if (set === 'notes') renderInlineNotes();
    if (set === 'xrefs') markXrefs();
    relayout();                 // the text has reflowed: the margin dots must follow it
  });

  // ---------- the chapter on screen ----------
  const article = () => $('.b-chapter', page);
  const notesOf = (el) => { try { return JSON.parse(el.dataset.notes || '[]'); } catch (e) { return []; } };

  // Notes shown under their verse, for the "in the text" setting.
  function renderInlineNotes() {
    const el = article();
    el.querySelectorAll('.b-inline-note').forEach((n) => n.remove());
    if (S.notes !== 'inline') return;
    const notes = notesOf(el);
    const byVerse = new Map();
    notes.forEach((n) => { byVerse.set(n.verse, [...(byVerse.get(n.verse) || []), n]); });
    for (const [verse, list] of byVerse) {
      const spans = el.querySelectorAll(`.v[data-v="${verse}"]`);
      const last = spans[spans.length - 1];
      if (!last) continue;
      const div = document.createElement('div');
      div.className = 'b-inline-note';
      div.innerHTML = list.map((n) => `<strong>${esc(n.marker)}</strong> ${n.kind === 'x' ? esc(n.refs) : n.body}`).join('<br>');
      last.closest('p')?.after(div);
    }
  }

  // One tap on a marker opens the note at the foot of the screen; it never covers its own verse.
  function openNote(index) {
    closeNote();
    const n = notesOf(article())[index];
    if (!n) return;
    const sheet = document.createElement('div');
    sheet.className = 'b-sheet';
    sheet.setAttribute('role', 'dialog');
    const where = `${article().querySelector('.b-title')?.textContent || ''}:${n.verse}`;
    sheet.innerHTML = `<h3>${esc(where)} · ${n.kind === 'x' ? 'Cross-reference' : 'Note'}</h3>`
      + (n.kind === 'x'
        ? `<p>${n.refs.split(';').map((r) => `<a class="b-xref" href="#" data-ref="${esc(r.trim())}">${esc(r.trim())}</a>`).join('; ')}</p>`
        : `<p>${n.body}</p>`);
    document.body.appendChild(sheet);
    // Keep the verse the note belongs to in view above the sheet.
    const verseEl = article().querySelector(`.v[data-v="${n.verse}"]`);
    const bottom = verseEl?.getBoundingClientRect().bottom ?? 0;
    const room = window.innerHeight - sheet.getBoundingClientRect().height;
    if (bottom > room) window.scrollBy({ top: bottom - room + 12, behavior: 'smooth' });
  }
  const closeNote = () => $('.b-sheet')?.remove();

  document.addEventListener('click', (e) => {
    const marker = e.target.closest('.fn, .xr, .b-dot');
    if (marker) {
      openNote(+marker.dataset.note);
      e.preventDefault();
      return;
    }
    if (!e.target.isConnected) return;
    if (!e.target.closest('.b-sheet')) closeNote();
  });

  // ---------- the commentaries ----------
  // A verse number opens what the commentators say about that verse, in the works this reader has
  // Which book and chapter something on the page belongs to. A chapter page is all one chapter, so
  // the article itself says; a day's reading is several passages, and each says for itself.
  function placeOf(el) {
    const part = el && el.closest ? el.closest('[data-book]') : null;
    return part
      ? { book: part.dataset.book, chapter: +part.dataset.chapter }
      : { book: APP.book, chapter: APP.chapter };
  }

  // chosen. Bible Hub stays at the foot of the sheet, for anything the app doesn't carry.
  const hubUrl = (verse) => {
    const el = article();
    return `https://biblehub.com/commentaries/${el.dataset.slug}/${el.dataset.chapter}-${verse}.htm`;
  };

  let works = [];      // the catalog, as the server last sent it

  page.addEventListener('click', (e) => {
    const num = e.target.closest('.vn');
    if (!num) return;
    e.stopPropagation();      // or this same click would count as one outside the sheet it opens
    openCommentary(+num.dataset.v, num);
  });

  async function openCommentary(verse, from = null) {
    closeNote();
    const at = placeOf(from);
    const ref = `${article().querySelector('.b-title')?.textContent || ''}:${verse}`;
    const sheet = document.createElement('div');
    sheet.className = 'b-sheet b-comm';
    sheet.setAttribute('role', 'dialog');
    sheet.innerHTML = `<h3>${esc(ref)} · commentary</h3><p class="b-comm-wait">Looking…</p>`;
    document.body.appendChild(sheet);
    try {
      const d = await commentaryFor(at.book, at.chapter, verse);
      works = d.works || [];
      drawCommentary(sheet, ref, verse, d, from);
    } catch (err) {
      sheet.innerHTML = `<h3>${esc(ref)} · commentary</h3>
        <p class="b-comm-wait">The commentary couldn’t be fetched.</p>
        ${APP.biblehub ? `<p><a class="b-xref" href="${hubUrl(verse)}" target="_blank" rel="noopener">Look it up on Bible Hub</a></p>` : ''}`;
    }
  }

  // A chapter's commentary, fetched once and kept for as long as the page is open. One request
  // per chapter instead of one per verse: quicker moving from verse to verse, far lighter on the
  // host, and it puts a whole chapter on the device in a single call — which is what makes the
  // commentary readable with no signal.
  const commCache = new Map();
  async function chapterCommentary(book, chapter) {
    const key = book + ':' + chapter;
    if (commCache.has(key)) return commCache.get(key);
    const p = (async () => {
      const res = await fetch(`${APP.base}api/commentary.php?b=${book}&c=${chapter}&verse=0`,
        { credentials: 'same-origin' });
      const d = await res.json();
      if (!res.ok) throw new Error(d.error || 'Nothing here.');
      return d;
    })();
    commCache.set(key, p);
    p.catch(() => commCache.delete(key));     // a failed fetch must not be remembered as the answer
    return p;
  }

  const commUrl = (book, chapter, verse) =>
    `${APP.base}api/commentary.php?b=${book}&c=${chapter}&verse=${verse}`;

  const forVerse = (d, verse) => ({
    ...d, verse, entries: (d.entries || []).filter((e) => verse >= e.from && verse <= e.to),
  });

  // Has the chapter already been fetched on this device — read earlier, or kept for today's
  // reading? Then it costs nothing to use, and works with no signal.
  async function chapterAlreadyHere(book, chapter) {
    try { return !!(await caches.match(commUrl(book, chapter, 0))); } catch (e) { return false; }
  }

  // What to show for a verse, as quickly as it can be shown.
  //
  // The whole chapter is the better thing to hold: every other verse in it then opens with no
  // request at all, and it is what makes the commentary readable with no signal. But it is several
  // times the size of a single verse, and waiting for it on a slow morning would be a step
  // backwards from what was here before. So — if the chapter is already on the device, use it; if
  // not, show the verse at once and fetch the chapter quietly behind it, once.
  async function commentaryFor(book, chapter, verse) {
    const key = book + ':' + chapter;
    if (commCache.has(key)) {
      const d = await commCache.get(key);
      if (!d.partial) return forVerse(d, verse);
    } else if (await chapterAlreadyHere(book, chapter)) {
      const d = await chapterCommentary(book, chapter);
      if (!d.partial) return forVerse(d, verse);
    }
    const res = await fetch(commUrl(book, chapter, verse), { credentials: 'same-origin' });
    const d = await res.json();
    if (!res.ok) throw new Error(d.error || 'Nothing here.');
    warmChapter(book, chapter);
    return d;
  }

  // The rest of the chapter, once the verse someone asked for is on screen and the page is quiet.
  function warmChapter(book, chapter) {
    if (commCache.has(book + ':' + chapter)) return;
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 1500));
    idle(() => { chapterCommentary(book, chapter).catch(() => {}); });
  }

  // The verses a comment covers. Newer replies carry them as numbers; an older one kept on the
  // device may only have the printed form ("1–2"), so that is read as well.
  function entryRange(entry, fallback) {
    if (entry.from) return [+entry.from, +(entry.to || entry.from)];
    const m = /^(\d+)(?:\s*[-–—]\s*(\d+))?/.exec(String(entry.verses || ''));
    return m ? [+m[1], +(m[2] || m[1])] : [+fallback, +fallback];
  }

  // What the passage is called, above its words. On a day's reading the page is headed by the date,
  // so the passage's own heading is what names the book.
  function placeLabel(from, to, within = null) {
    const part = within && within.closest ? within.closest('.b-part') : null;
    let head = '';
    if (part) {
      // A day's reading: the passage's own heading names the book and chapter, and the page's
      // heading is only the date. A passage spanning chapters carries its own heading inside.
      head = part.querySelector('h3.b-s')?.textContent || '';
      for (let p = part.previousElementSibling; p && !head; p = p.previousElementSibling) {
        if (p.matches?.('h2.b-ms')) head = p.textContent;
      }
    } else {
      head = article().querySelector('.b-title')?.textContent || '';
    }
    head = String(head).split(':')[0].trim();
    const range = from === to ? String(from) : `${from}–${to}`;
    return head ? `${head}:${range}` : `Verses ${range}`;
  }

  // The words themselves, taken from the chapter on the page — so they are in whichever translation
  // is being read, not whichever one the commentator quoted. A comment on a long stretch is headed
  // by its opening verses rather than by the whole of it, which would bury the commentary.
  const VERSES_SHOWN = 6;
  function versesHtml(from, to, within = null) {
    const el = (within && within.closest && within.closest('.b-part')) || article();
    const parts = [];
    let shown = 0;
    for (let v = from; v <= to && shown < VERSES_SHOWN; v++) {
      const span = el.querySelector(`.v[data-v="${v}"]`);
      if (!span) continue;
      parts.push((from === to ? '' : `<span class="b-comm-vn">${v}</span> `) + esc(verseText(span)));
      shown++;
    }
    if (!parts.length) return '';
    const more = to - from + 1 > shown ? ' …' : '';
    return `<span class="b-comm-ref">${esc(placeLabel(from, to, within))}</span> ${parts.join(' ')}${more}`;
  }

  const RAIL_GAP = 16;        // matches .b-comm-rail's gap in bible.css

  // One commentary at a time, the next one a swipe to the left, and the way to choose which
  // works appear kept at the foot of the sheet.
  function drawCommentary(sheet, ref, verse, d, from = null) {
    const here = works.filter((w) => w.here);
    const entries = d.entries || [];
    const foot = `<div class="b-acts b-comm-foot">
        <button data-comm="choose">Choose</button>
        ${APP.biblehub ? `<a class="b-comm-more" href="${hubUrl(verse)}" target="_blank" rel="noopener">Bible Hub</a>` : ''}
        <button data-comm="look" aria-label="How the commentary is set">Aa</button>
      </div>`;
    if (!entries.length) {
      sheet.innerHTML = `<h3>${esc(ref)} · commentary</h3>
        <p class="b-comm-wait">${here.length
          ? 'None of the commentaries you’ve chosen has a note on this verse.'
          : 'No commentary has been imported for this chapter yet.'}</p>${foot}`;
      sheet.querySelector('[data-comm="choose"]')?.addEventListener('click', (ev) => {
        ev.stopPropagation();
        chooseWorks(verse, d.chosen);
      });
      sheet.querySelector('[data-comm="look"]')?.addEventListener('click', (ev) => {
        ev.stopPropagation();
        lookPanel(sheet);
      });
      return;
    }
    sheet.innerHTML = `<div class="b-comm-head">
        <button class="b-comm-step b-comm-back" aria-label="The commentary before this one">‹</button>
        <!-- Who wrote it and which verses it covers: a label for the eye. Read aloud it comes
             before every comment as "Matthew Henry, 1706, verses 1 to 2, 1 of 3", which is not
             what anybody is listening for, so it is kept out of the accessibility tree and out
             of anything reading the page. -->
        <div class="b-comm-name" aria-hidden="true">
          <span class="b-comm-who"></span>
          <span class="b-comm-when"></span>
        </div>
        <button class="b-comm-step b-comm-on" aria-label="The next commentary">›</button>
        <button class="b-comm-close" aria-label="Back to the text">✕</button>
      </div>
      <p class="b-comm-verse"></p>
      <div class="b-comm-track"><div class="b-comm-rail">${entries.map((x) => `<article class="b-comm-page">${x.html}</article>`).join('')}</div></div>
      ${foot}`;

    // Tapping outside used to be the only way out, and with the commentary filling the screen there
    // is no outside to tap.
    sheet.querySelector('.b-comm-close').addEventListener('click', (ev) => {
      ev.stopPropagation();
      closeNote();
    });

    const track = sheet.querySelector('.b-comm-track');
    const rail = sheet.querySelector('.b-comm-rail');   // the rail moves; the track clips it
    const who = sheet.querySelector('.b-comm-who');
    const when = sheet.querySelector('.b-comm-when');
    const said = sheet.querySelector('.b-comm-verse');
    let at = 0;

    const show = (i, animate = true) => {
      at = Math.max(0, Math.min(entries.length - 1, i));
      const x = entries[at];
      who.textContent = x.name;
      // The verses this particular comment is about — which is not always the one that was tapped,
      // and differs from one commentator to the next, so it follows the rail.
      const [a, b] = entryRange(x, verse);
      said.innerHTML = versesHtml(a, b, from);
      said.hidden = !said.innerHTML;
      when.textContent = [x.years || x.edition, x.verses !== String(verse) ? `verses ${x.verses}` : '',
        entries.length > 1 ? `${at + 1} of ${entries.length}` : ''].filter(Boolean).join(' · ');
      rail.style.transition = animate ? 'transform .22s cubic-bezier(.22,.7,.3,1)' : '';
      // In pixels, not per cent: a percentage would be measured against the rail's own width,
      // which is one page wide however many pages hang off it. The gap keeps the neighboring
      // page's first letters from showing at the edge.
      rail.style.transform = `translateX(${-at * (track.clientWidth + RAIL_GAP)}px)`;
      sheet.querySelector('.b-comm-back').disabled = at === 0;
      sheet.querySelector('.b-comm-on').disabled = at === entries.length - 1;
      track.querySelectorAll('.b-comm-page').forEach((p, n) => { p.scrollTop = n === at ? p.scrollTop : 0; });
    };
    show(0, false);
    const onResize = () => show(at, false);
    window.addEventListener('resize', onResize);
    sheet.addEventListener('b-closed', () => window.removeEventListener('resize', onResize));

    sheet.querySelector('.b-comm-back').addEventListener('click', (ev) => { ev.stopPropagation(); show(at - 1); });
    sheet.querySelector('.b-comm-on').addEventListener('click', (ev) => { ev.stopPropagation(); show(at + 1); });

    // Swiping across the text moves to the next commentary; swiping down the page still scrolls.
    let x0 = null, y0 = null, moving = false;
    track.addEventListener('touchstart', (e) => {
      if (e.touches.length !== 1) { x0 = null; return; }
      x0 = e.touches[0].clientX;
      y0 = e.touches[0].clientY;
      moving = false;
    }, { passive: true });
    track.addEventListener('touchmove', (e) => {
      if (x0 === null) return;
      const dx = e.touches[0].clientX - x0;
      const dy = e.touches[0].clientY - y0;
      if (!moving) {
        if (Math.abs(dy) > Math.abs(dx)) { x0 = null; return; }
        if (Math.abs(dx) < 12) return;
        moving = true;
      }
      // At either end there is nothing to move to, so the page barely gives.
      const held = (dx < 0 && at === entries.length - 1) || (dx > 0 && at === 0) ? 0.25 : 1;
      rail.style.transition = '';
      rail.style.transform = `translateX(${-at * (track.clientWidth + RAIL_GAP) + dx * held}px)`;
    }, { passive: true });
    const settle = (e) => {
      if (x0 === null || !moving) { x0 = null; return; }
      const dx = (e.changedTouches ? e.changedTouches[0].clientX : 0) - x0;
      x0 = null;
      moving = false;
      const far = Math.min(90, window.innerWidth * 0.22);
      show(Math.abs(dx) < far ? at : at - Math.sign(dx));
    };
    track.addEventListener('touchend', settle, { passive: true });
    track.addEventListener('touchcancel', () => { x0 = null; moving = false; show(at); }, { passive: true });

    sheet.querySelector('[data-comm="choose"]')?.addEventListener('click', (ev) => {
      ev.stopPropagation();
      chooseWorks(verse, d.chosen);
    });
    sheet.querySelector('[data-comm="look"]')?.addEventListener('click', (ev) => {
      ev.stopPropagation();
      lookPanel(sheet);
    });
  }

  // How the commentary reads: its own size and face, kept with the other settings and so with
  // the account. The commentary stays on screen behind the panel, so a change is seen at once.
  function lookPanel(sheet) {
    sheet.querySelector('.b-look')?.remove();
    const panel = document.createElement('div');
    panel.className = 'b-look';
    const face = (k, label) => `<button data-cfont="${k}"${(S.cfont || S.font) === k ? ' class="on"' : ''}>${label}</button>`;
    panel.innerHTML = `<div class="b-look-row"><span>Size</span>
        <button data-csize="-1" aria-label="Smaller">A−</button>
        <button data-csize="1" aria-label="Larger">A+</button></div>
      <div class="b-look-row b-look-faces">${face('serif', 'Serif')}${face('system', 'System')}${face('legible', 'Hyperlegible')}</div>
      <div class="b-look-row"><button data-look="done" class="primary">Done</button></div>`;
    sheet.appendChild(panel);
    panel.addEventListener('click', (e) => {
      e.stopPropagation();
      const step = e.target.closest('[data-csize]')?.dataset.csize;
      const font = e.target.closest('[data-cfont]')?.dataset.cfont;
      if (step) {
        const now = S.csize >= 0 ? S.csize : S.size;
        S.csize = Math.max(0, Math.min(SIZES.length - 1, now + (+step)));
      } else if (font) {
        S.cfont = font;
        panel.querySelectorAll('[data-cfont]').forEach((x) => x.classList.toggle('on', x.dataset.cfont === font));
      } else if (e.target.closest('[data-look="done"]')) {
        panel.remove();
        return;
      } else {
        return;
      }
      applySettings();
    });
  }

  // Which works to show: one, two or as many as you like, remembered on the account.
  function chooseWorks(verse, chosen) {
    const sheet = $('.b-comm');
    if (!sheet) return;
    const picked = new Set(chosen);
    const row = (w) => `<label class="b-work${w.here ? '' : ' b-work-away'}">
        <input type="checkbox" value="${w.code}"${picked.has(w.code) ? ' checked' : ''}>
        <span class="b-work-name">${esc(w.name)}</span>
        <span class="b-work-when">${esc(w.years || '')}${w.here ? '' : ' · not in this chapter'}</span>
      </label>`;
    sheet.innerHTML = `<h3>Commentaries to show</h3>
      <div class="b-works">${works.map(row).join('')}</div>
      <div class="b-acts">
        <button data-comm="all">Show every one</button>
        <button data-comm="done" class="primary">Done</button>
      </div>`;
    sheet.addEventListener('click', async (e) => {
      const act = e.target.closest('[data-comm]')?.dataset.comm;
      // 'choose' is the click that opened this list, still on its way up: it isn't for us.
      if (act !== 'all' && act !== 'done') return;
      e.stopPropagation();
      if (act === 'all') {
        sheet.querySelectorAll('.b-works input').forEach((i) => { i.checked = true; });
        return;
      }
      const codes = [...sheet.querySelectorAll('.b-works input:checked')].map((i) => i.value);
      await fetch(`${APP.base}api/commentary.php`, {
        method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf },
        body: new URLSearchParams({ works: JSON.stringify(codes) }),
      }).catch(() => {});
      commCache.clear();      // a different set of works means a different answer for every verse
      openCommentary(verse);
    });
  }

  // A dot in the margin for verses that carry a note (Tecarta's idea: visible, easy to hit).
  function renderDots() {
    const el = article();
    el.querySelectorAll('.b-dot').forEach((d) => d.remove());
    if (S.notes !== 'markers' || S.plain) return;
    const seen = new Set();
    notesOf(el).forEach((n, i) => {
      if (seen.has(n.verse)) return;
      seen.add(n.verse);
      const span = el.querySelector(`.v[data-v="${n.verse}"]`);
      const p = span?.closest('p');
      if (!p) return;
      p.style.position = 'relative';
      const dot = document.createElement('button');
      dot.className = 'b-dot' + (n.kind === 'x' ? ' x' : '');
      dot.dataset.note = String(i);
      dot.setAttribute('aria-hidden', 'true');
      dot.tabIndex = -1;
      dot.style.top = (span.offsetTop + 6) + 'px';
      p.appendChild(dot);
    });
  }

  // The note dots sit in the margin at a measured height, so anything that reflows the text —
  // a different size or font, a turned phone, a late-loading font — has to put them back.
  let relayoutTimer = null;
  function relayout() {
    clearTimeout(relayoutTimer);
    relayoutTimer = setTimeout(renderDots, 60);
  }
  window.addEventListener('resize', relayout);
  window.addEventListener('orientationchange', relayout);
  if (document.fonts?.ready) document.fonts.ready.then(relayout).catch(() => {});

  // ---------- cross-references ----------
  // Verses that have them are underlined with a dotted line (quiet, but there when you look for
  // it). Tapping one opens the list: each reference with its own text, and tappable to go there.
  function markXrefs() {
    const el = article();
    let verses = [];
    try { verses = JSON.parse(el.dataset.xrefs || '[]'); } catch (e) { /* none */ }
    el.querySelectorAll('.xr-word').forEach((w) => w.replaceWith(document.createTextNode(w.textContent)));
    el.normalize();
    if (!S.xrefs || S.plain) return;
    for (const v of verses) {
      const span = el.querySelector(`.v[data-v="${v}"]`);
      if (span) underlineOneWord(span, v);
    }
  }

  // One word carries the dotted underline, not the whole verse: enough to show there is something
  // to tap, without underlining the page. The first word long enough to be a comfortable target.
  function underlineOneWord(span, verse) {
    const walk = document.createTreeWalker(span, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walk.nextNode())) {
      if (node.parentElement.closest('sup, .xr-word')) continue;
      const m = /\p{L}{4,}/u.exec(node.nodeValue);
      if (!m) continue;
      const after = node.splitText(m.index);
      after.splitText(m[0].length);
      const a = document.createElement('span');
      a.className = 'xr-word';
      a.dataset.v = String(verse);
      a.textContent = after.nodeValue;
      after.replaceWith(a);
      return;
    }
  }

  async function openXrefs(verse) {
    closeNote();
    const el = article();
    const sheet = document.createElement('div');
    sheet.className = 'b-sheet b-xrefs';
    sheet.setAttribute('role', 'dialog');
    sheet.innerHTML = `<h3>${esc(el.querySelector('.b-title')?.textContent || '')}:${verse} · Cross-references</h3><p class="b-loading">Looking…</p>`;
    document.body.appendChild(sheet);
    try {
      const res = await fetch(`${APP.base}api/bible.php?action=xrefs&v=${APP.version}&b=${el.dataset.book}&c=${el.dataset.chapter}&verse=${verse}`,
        { credentials: 'same-origin' });
      const data = await res.json();
      const refs = data.refs || [];
      sheet.innerHTML = `<h3>${esc(el.querySelector('.b-title')?.textContent || '')}:${verse} · ${refs.length} cross-reference${refs.length === 1 ? '' : 's'}</h3>`
        + (refs.length
          ? `<ul class="b-xlist">` + refs.map((r) =>
            `<li><button data-x="${r.book}:${r.chapter}:${r.verse}" data-ref="${r.book}.${r.chapter}.${r.verse}">`
            + `<span class="b-xref">${esc(r.ref)}</span>`
            + `<span class="b-xtext">${esc(r.text)}</span></button></li>`).join('') + '</ul>'
          : '<p>None for this verse.</p>');
      matchXrefsToVersion(sheet);
    } catch (e) {
      sheet.innerHTML = '<p>Couldn’t fetch those just now.</p>';
    }
  }

  // A cross-reference list quotes whatever text we hold. While one of the fetched translations is
  // being read, the words shown are the KJV's; these ask for that translation's own and put them in
  // as they arrive, so the list is right without being slow. Each one is kept, so the next person
  // to open the same list costs nothing.
  async function matchXrefsToVersion(sheet) {
    if (!(APP.fetched || []).includes(APP.version)) return;
    const rows = [...sheet.querySelectorAll('[data-ref]')];
    if (!rows.length) return;
    try {
      const res = await fetch(`${APP.base}api/bible.php?action=xreftext&v=${APP.version}`
        + `&refs=${rows.map((r) => r.dataset.ref).join(',')}`, { credentials: 'same-origin' });
      const { texts } = await res.json();
      if (!texts) return;
      for (const row of rows) {
        const t = texts[row.dataset.ref];
        if (t && row.isConnected) $('.b-xtext', row).textContent = t;
      }
    } catch (err) { /* the KJV's words are still there, which is not wrong, only not his */ }
  }

  document.addEventListener('click', (e) => {
    const go_x = e.target.closest('[data-x]');
    if (go_x) {
      const [book, chapter, verse] = go_x.dataset.x.split(':');
      closeNote();
      go(book, +chapter, true, +verse);
      return;
    }
    const word = e.target.closest('.xr-word');
    if (word && S.xrefs) {
      openXrefs(+word.dataset.v);
    }
  });

  // ---------- a person's own marks ----------
  // Highlights, bookmarks and notes belong to the account, so they follow you to a new phone.
  // They're all held here after one fetch: there are rarely many, and reading offline then still
  // shows them. A mark made with no signal waits in a queue until there is one.
  const COLORS = ['yellow', 'green', 'blue', 'pink', 'orange'];
  let marks = [];
  const marksFor = (book, chapter) => marks.filter((m) => m.book === book && m.chapter === chapter);
  const markOn = (kind, verse) => marksFor(APP.book, APP.chapter)
    .find((m) => m.kind === kind && m.verse <= verse && m.end_verse >= verse);

  const rememberMarks = () => {
    try { localStorage.setItem('bible-marks', JSON.stringify(marks)); } catch (e) { /* private window */ }
  };
  try { marks = JSON.parse(localStorage.getItem('bible-marks') || '[]'); } catch (e) { marks = []; }

  const queue = () => { try { return JSON.parse(localStorage.getItem('bible-marks-queue') || '[]'); } catch (e) { return []; } };
  const setQueue = (q) => { try { localStorage.setItem('bible-marks-queue', JSON.stringify(q)); } catch (e) { /* none */ } };

  const postMark = (body) => fetch(`${APP.base}api/marks.php`, {
    method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf },
    body: new URLSearchParams(body),
  }).then((r) => r.json());

  // Anything marked while offline goes up as soon as the marks are fetched again.
  async function flushQueue() {
    const q = queue();
    if (!q.length) return;
    setQueue([]);
    for (const body of q) {
      try { await postMark(body); } catch (err) { setQueue([...queue(), body]); }
    }
  }

  function loadMarks() {
    fetch(`${APP.base}api/marks.php`, { credentials: 'same-origin' })
      .then((r) => r.json())
      .then(async (d) => {
        if (!Array.isArray(d.marks)) return;
        await flushQueue();
        marks = d.marks;
        rememberMarks();
        applyMarks();
      })
      .catch(() => { /* offline: what's remembered here still shows */ });
  }

  // Puts the marks on the chapter: a tint behind highlighted verses, and a small flag at the end
  // of a verse that's bookmarked or has a note on it. The flags are hidden from a screen reader,
  // which reads the verse itself.
  function applyMarks() {
    const el = article();
    if (!el) return;
    el.querySelectorAll('.b-flag').forEach((f) => f.remove());
    el.querySelectorAll('.v').forEach((v) => {
      v.className = v.className.replace(/\bb-hl\S*/g, '').trim();
      v.classList.remove('b-bk', 'b-noted');
    });
    for (const m of marksFor(el.dataset.book, +el.dataset.chapter)) {
      for (let v = m.verse; v <= m.end_verse; v++) {
        const span = el.querySelector(`.v[data-v="${v}"]`);
        if (!span) continue;
        if (m.kind === 'highlight') {
          span.classList.add('b-hl', 'b-hl-' + (m.color || 'yellow'));
        } else {
          span.classList.add(m.kind === 'bookmark' ? 'b-bk' : 'b-noted');
          if (v === m.verse) {
            const flag = document.createElement('button');
            flag.className = 'b-flag b-flag-' + m.kind;
            flag.dataset.mark = m.id;
            flag.setAttribute('aria-hidden', 'true');
            flag.tabIndex = -1;
            flag.textContent = m.kind === 'bookmark' ? '\u25e5' : '\u270e';
            span.appendChild(flag);
          }
        }
      }
    }
  }

  // Tapping a note's flag opens what you wrote.
  document.addEventListener('click', (e) => {
    const flag = e.target.closest('.b-flag-note');
    if (!flag) return;
    const m = marks.find((x) => String(x.id) === flag.dataset.mark);
    const span = flag.closest('.v');
    if (m && span) openNoteEditor(span, m);
  });

  // Marking verses. The page is changed at once and the server told after, so nothing waits on
  // the network; if the network is out, the change is queued.
  function mark(kind, verse, color = '', body = '') {
    const book = APP.book, chapter = APP.chapter;
    const gone = marks.filter((m) => !(m.kind === kind && m.book === book && m.chapter === chapter
      && m.verse <= verse && m.end_verse >= verse));
    const add = { id: 'new-' + Date.now(), kind, book, chapter, verse, end_verse: verse, color, body };
    marks = [...gone, add];
    rememberMarks();
    applyMarks();
    const payload = { kind, book, chapter, verse, end_verse: verse, color, body, version: APP.version };
    postMark(payload)
      .then((d) => {
        if (d && d.mark) {
          marks = marks.map((m) => (m.id === add.id ? d.mark : m));
          rememberMarks();
          applyMarks();
        }
      })
      .catch(() => setQueue([...queue(), payload]));
  }

  function unmark(kind, verse) {
    const book = APP.book, chapter = APP.chapter;
    marks = marks.filter((m) => !(m.kind === kind && m.book === book && m.chapter === chapter
      && m.verse <= verse && m.end_verse >= verse));
    rememberMarks();
    applyMarks();
    const payload = { action: 'clear', kind, book, chapter, verse, end_verse: verse };
    postMark(payload).catch(() => setQueue([...queue(), payload]));
  }

  // Writing a note on a verse: the sheet becomes a box to write in.
  function openNoteEditor(span, existing) {
    closeNote();
    const verse = +span.dataset.v;
    const ref = `${article().querySelector('.b-title')?.textContent || ''}:${verse}`;
    const sheet = document.createElement('div');
    sheet.className = 'b-sheet b-noteform';
    sheet.setAttribute('role', 'dialog');
    sheet.innerHTML = `<h3>${esc(ref)}</h3>
      <textarea class="b-notebox" rows="5" maxlength="4000" placeholder="Your note on this verse"></textarea>
      <div class="b-acts">
        <button data-note-act="save" class="primary">Save</button>
        ${existing ? '<button data-note-act="delete">Delete</button>' : ''}
        <button data-note-act="cancel">Cancel</button>
      </div>`;
    document.body.appendChild(sheet);
    const box = sheet.querySelector('.b-notebox');
    box.value = existing?.body || '';
    box.focus();
    sheet.addEventListener('click', (e) => {
      const act = e.target.closest('[data-note-act]')?.dataset.noteAct;
      if (!act) return;
      if (act === 'save' && box.value.trim()) mark('note', verse, '', box.value.trim());
      if (act === 'delete') unmark('note', verse);
      closeNote();
    });
  }

  // ---------- what you can do with a verse ----------
  // Holding a verse (or right-clicking one) offers its actions. A tap is left alone: tapping is
  // for reading, and the verse number and the underlined word have their own meanings.
  function verseText(span) {
    const clone = span.cloneNode(true);
    clone.querySelectorAll('sup, .b-dot, .b-flag').forEach((x) => x.remove());
    return clone.textContent.replace(/\s+/g, ' ').trim();
  }

  function openVerseActions(span) {
    closeNote();
    const el = article();
    const verse = span.dataset.v;
    const ref = `${el.querySelector('.b-title')?.textContent || ''}:${verse}`;
    const sheet = document.createElement('div');
    sheet.className = 'b-sheet b-actions';
    sheet.setAttribute('role', 'dialog');
    const hl = markOn('highlight', +verse);
    const bk = markOn('bookmark', +verse);
    const note = markOn('note', +verse);
    sheet.innerHTML = `<h3>${esc(ref)} · ${esc(APP.version)}</h3>
      <p class="b-quoted">${esc(verseText(span))}</p>
      <div class="b-swatches">
        ${COLORS.map((c) => `<button class="b-sw b-sw-${c}${hl?.color === c ? ' on' : ''}" data-hl="${c}" aria-label="Highlight ${c}"></button>`).join('')}
        <button class="b-sw b-sw-off${hl ? '' : ' on'}" data-hl="" aria-label="No highlight">✕</button>
      </div>
      <div class="b-acts">
        <button data-act="bookmark">${bk ? 'Remove bookmark' : 'Bookmark'}</button>
        <button data-act="note">${note ? 'Edit note' : 'Note'}</button>
        <button data-act="quote">Quote in the chat</button>
        <button data-act="copy">Copy</button>
        <button data-act="xrefs">Cross-references</button>
      </div>`;
    document.body.appendChild(sheet);
    sheet.addEventListener('click', async (e) => {
      const color = e.target.closest('[data-hl]')?.dataset.hl;
      if (color !== undefined) {
        if (color) mark('highlight', +verse, color); else unmark('highlight', +verse);
        closeNote();
        return;
      }
      const act = e.target.closest('[data-act]')?.dataset.act;
      if (!act) return;
      const text = verseText(span);
      if (act === 'bookmark') {
        if (bk) unmark('bookmark', +verse); else mark('bookmark', +verse);
        closeNote();
        return;
      }
      if (act === 'note') {
        openNoteEditor(span, note);
        return;
      }
      if (act === 'copy') {
        navigator.clipboard?.writeText(`${text}\n— ${ref} (${APP.version})`);
        e.target.textContent = 'Copied';
        setTimeout(closeNote, 700);
      } else if (act === 'quote') {
        // The chat picks this up and asks which topic to send it to.
        try {
          sessionStorage.setItem('bible-quote', JSON.stringify({ text, ref, version: APP.version }));
        } catch (err) { /* private window */ }
        location.href = APP.base;
      } else if (act === 'xrefs') {
        openXrefs(+verse);
      }
    });
  }

  let holdTimer = null;
  // Lifting the finger after a hold fires a click, which would close the sheet the hold just
  // opened; so the next click is swallowed.
  const swallowNextClick = () => {
    const stop = (ev) => { ev.stopPropagation(); ev.preventDefault(); };
    document.addEventListener('click', stop, { capture: true, once: true });
    setTimeout(() => document.removeEventListener('click', stop, { capture: true }), 700);
  };
  // A hold is also how a phone starts selecting text, so it leaves the verse number and the first
  // word or two highlighted behind the sheet. The sheet is the answer to the hold; the selection
  // is not, so it goes. The later passes catch the platform's own, which lands after ours.
  const dropSelection = () => {
    const sel = window.getSelection();
    if (sel && !sel.isCollapsed) sel.removeAllRanges();
  };
  page.addEventListener('touchstart', (e) => {
    const span = e.target.closest('.v');
    if (!span || e.touches.length !== 1) return;
    holdTimer = setTimeout(() => {
      holdTimer = null;
      swallowNextClick();
      openVerseActions(span);
      dropSelection();
      setTimeout(dropSelection, 80);
      setTimeout(dropSelection, 320);
    }, 480);
  }, { passive: true });
  const cancelHold = () => { clearTimeout(holdTimer); holdTimer = null; };
  page.addEventListener('touchmove', cancelHold, { passive: true });
  page.addEventListener('touchend', cancelHold, { passive: true });
  page.addEventListener('contextmenu', (e) => {
    const span = e.target.closest('.v');
    if (!span) return;
    e.preventDefault();
    openVerseActions(span);
    dropSelection();
  });

  // ---------- the version kept on this device ----------
  // A whole version can be downloaded (about 9 MB), after which every chapter, note and
  // cross-reference is read from the browser's own database and the reader works with no signal.
  let have = {};              // the versions this device holds
  let getting = '';           // the one being downloaded, if any
  let progress = 0;

  function db() {
    if (db.p) return db.p;
    db.p = new Promise((resolve, reject) => {
      const req = indexedDB.open('bible-offline', 1);
      req.onupgradeneeded = () => {
        const d = req.result;
        if (!d.objectStoreNames.contains('chapters')) d.createObjectStore('chapters');
        if (!d.objectStoreNames.contains('versions')) d.createObjectStore('versions');
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    return db.p;
  }

  const idb = (store, mode, fn) => db().then((d) => new Promise((resolve, reject) => {
    const tx = d.transaction(store, mode);
    const req = fn(tx.objectStore(store));
    tx.oncomplete = () => resolve(req && 'result' in req ? req.result : null);
    tx.onerror = tx.onabort = () => reject(tx.error);
  }));

  const localChapter = (book, chapter) =>
    idb('chapters', 'readonly', (s) => s.get(`${APP.version}:${book}:${chapter}`)).catch(() => null);

  // Offline, what comes before and after a chapter is worked out here rather than by the server.
  function stepLocal(book, chapter, d) {
    const i = APP.books.findIndex((b) => b.code === book);
    if (i < 0) return null;
    const c = chapter + d;
    if (c >= 1 && c <= APP.books[i].chapters) return { book, chapter: c, name: APP.books[i].name };
    const n = APP.books[i + d];
    return n ? { book: n.code, chapter: d > 0 ? 1 : n.chapters, name: n.name } : null;
  }

  const fromLocal = (book, chapter, row) => ({
    version: APP.version, book, chapter, name: row.name, biblehub: row.biblehub,
    chapters: (APP.books.find((b) => b.code === book) || {}).chapters || 1,
    html: row.html, notes: row.notes, xrefs: row.xrefs,
    prev: stepLocal(book, chapter, -1), next: stepLocal(book, chapter, 1),
  });

  // A translation read from API.Bible can't be kept here: the licence allows a chapter at a time,
  // not a copy of the book.
  const canKeep = (v) => !(APP.fetched || []).includes(v);

  function offlineLabel() {
    const el = $('[data-val="offline"]');
    if (!el) return;
    if (!canKeep(APP.version)) { el.textContent = 'Not for this one'; return; }
    el.textContent = getting ? `${Math.round(progress * 100)}%` : (have[APP.version] ? 'On' : 'Off');
  }

  idb('versions', 'readonly', (s) => s.getAll())
    .then((rows) => { (rows || []).forEach((r) => { have[r.version] = r; }); offlineLabel(); })
    .catch(() => {});

  // A book at a time, so a dropped connection costs only that book and the bar keeps moving.
  async function download(version) {
    getting = version;
    progress = 0;
    offlineLabel();
    try {
      for (let i = 0; i < APP.books.length; i++) {
        const res = await fetch(`${APP.base}api/bible.php?action=book&v=${version}&b=${APP.books[i].code}`,
          { credentials: 'same-origin' });
        if (!res.ok) throw new Error('download failed');
        const bundle = await res.json();
        await idb('chapters', 'readwrite', (s) => {
          for (const ch of bundle.chapters) {
            s.put({ html: ch.html, notes: ch.notes, xrefs: ch.xrefs, name: bundle.name, biblehub: bundle.biblehub },
              `${version}:${bundle.book}:${ch.chapter}`);
          }
        });
        progress = (i + 1) / APP.books.length;
        offlineLabel();
      }
      const row = { version, at: Date.now() };
      await idb('versions', 'readwrite', (s) => s.put(row, version));
      have[version] = row;
    } catch (err) {
      alert('The download stopped. Check your connection and try again — what came down is kept.');
    } finally {
      getting = '';
      offlineLabel();
    }
  }

  async function removeDownload(version) {
    await idb('chapters', 'readwrite', (s) => s.delete(IDBKeyRange.bound(version + ':', version + ':\uffff')));
    await idb('versions', 'readwrite', (s) => s.delete(version));
    delete have[version];
    cache.clear();
    offlineLabel();
  }

  document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-offline]')) return;
    if (getting) return;
    if (!canKeep(APP.version)) {
      alert(`${APP.version} is read from its publisher as you go, so it can't be kept on this device. `
        + `${APP.versions.filter(canKeep).join(', ')} can.`);
      return;
    }
    if (have[APP.version]) {
      if (confirm(`Remove the copy of ${APP.version} kept on this device?`)) removeDownload(APP.version).catch(() => {});
    } else if (confirm(`Keep ${APP.version} on this device, so it reads with no signal? That's about 9 MB.`)) {
      download(APP.version);
    }
  });

  // ---------- where you were in a chapter ----------
  // Coming back to a chapter puts you where you left off in it, not at its head. Kept on the
  // device, since it is about this screen rather than about the account.
  // Where you had read to. A day's reading is several passages rather than a chapter, so it is
  // remembered against the day: otherwise every date whose first passage begins in the same
  // chapter would share one place, and would share it with the reader besides.
  const placeKey = (book, chapter) => (APP.reading
    ? `bible-read-at:${APP.reading}`
    : `bible-at:${book}:${chapter}`);
  const rememberPlace = () => {
    try {
      const y = Math.round(window.scrollY);
      const key = placeKey(APP.book, APP.chapter);
      if (y > 40) localStorage.setItem(key, String(y)); else localStorage.removeItem(key);
    } catch (e) { /* private window */ }
  };
  const placeIn = (book, chapter) => {
    try { return Math.max(0, parseInt(localStorage.getItem(placeKey(book, chapter)) || '0', 10)); }
    catch (e) { return 0; }
  };
  // Put the page back where it was, once the text has been laid out and the images (if any) sized.
  const goToPlace = (book, chapter) => {
    const y = placeIn(book, chapter);
    window.scrollTo(0, y);
    if (y) requestAnimationFrame(() => window.scrollTo(0, Math.min(y, document.body.scrollHeight)));
  };
  let placeTimer = null;
  window.addEventListener('scroll', () => {
    clearTimeout(placeTimer);
    placeTimer = setTimeout(rememberPlace, 250);
  }, { passive: true });
  window.addEventListener('pagehide', rememberPlace);

  // ---------- moving between chapters ----------
  // The chapters either side are fetched as soon as one is read, so a page turn never waits.
  const cache = new Map();
  const key = (book, chapter) => `${APP.version}:${book}:${chapter}`;

  async function fetchChapter(book, chapter) {
    const k = key(book, chapter);
    if (cache.has(k)) return cache.get(k);
    let ch = null;
    if (have[APP.version]) {
      const row = await localChapter(book, chapter);
      if (row) ch = fromLocal(book, chapter, row);
    }
    if (!ch) {
      try {
        const res = await fetch(`${APP.base}api/bible.php?v=${APP.version}&b=${book}&c=${chapter}`, { credentials: 'same-origin' });
        ch = await res.json();
        if (!res.ok) throw new Error(ch.error || 'That chapter isn’t there.');
      } catch (err) {
        const row = await localChapter(book, chapter);   // no signal: read what's here, if anything
        if (!row) throw err;
        ch = fromLocal(book, chapter, row);
      }
    }
    cache.set(k, ch);
    if (cache.size > 12) cache.delete(cache.keys().next().value);
    return ch;
  }

  function prefetchNeighbors() {
    for (const link of [$('.b-prev'), $('.b-next')]) {
      const [book, chapter] = (link?.dataset.go || '').split(':');
      if (book) fetchChapter(book, +chapter).catch(() => {});
    }
  }

  let busy = false;
  async function go(book, chapter, push = true, verse = 0, turning = false) {
    if (busy) return;
    busy = true;
    rememberPlace();          // where we were in the chapter being left
    try {
      const ch = await fetchChapter(book, chapter);
      const el = article();
      el.dataset.book = ch.book;
      el.dataset.chapter = ch.chapter;
      el.dataset.slug = ch.biblehub;
      el.dataset.notes = JSON.stringify(ch.notes);
      // A fetched translation carries its publisher's copyright line, which is shown with it.
      el.innerHTML = `<h1 class="b-title">${esc(ch.name)} ${ch.chapter}</h1>` + ch.html
        + (ch.notice ? `<p class="b-notice">${esc(ch.notice)}</p>` : '');
      APP.book = ch.book;
      APP.chapter = ch.chapter;
      $('.b-ref').textContent = `${ch.name} ${ch.chapter}`;
      document.title = `${ch.name} ${ch.chapter}`;
      steps(ch);
      prefetchNeighbors();
      renderDots();
      renderInlineNotes();
      markXrefs();
      applyMarks();
      closeNote();
      if (verse) {
        const target = el.querySelector(`.v[data-v="${verse}"]`);
        target?.scrollIntoView({ block: 'center' });
        target?.classList.add('b-flash');
        setTimeout(() => target?.classList.remove('b-flash'), 1600);
      } else {
        goToPlace(ch.book, ch.chapter);
      }
      if (!turning) {
        el.style.transition = '';
        el.style.transform = '';
        el.style.opacity = '';
      }
      if (push) history.pushState({ book: ch.book, chapter: ch.chapter }, '', `${APP.base}read/${ch.book}/${ch.chapter}?v=${APP.version}`);
      fetch(`${APP.base}api/bible.php`, {
        method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf },
        body: new URLSearchParams({ v: APP.version, b: ch.book, c: ch.chapter, verse: 1 }),
      }).then((r) => r.json()).then((d) => { if (d.recent) APP.recent = d.recent; }).catch(() => {});
    } catch (err) {
      /* stay where we are */
    } finally {
      busy = false;
    }
  }

  function steps(ch) {
    const nav = $('.b-steps');
    const link = (p, cls, text) => p
      ? `<a class="${cls}" href="${APP.base}read/${p.book}/${p.chapter}?v=${APP.version}" data-go="${p.book}:${p.chapter}">${esc(text(p))}</a>`
      : '<span></span>';
    nav.innerHTML = link(ch.prev, 'b-prev', (p) => `‹ ${p.name} ${p.chapter}`)
      + link(ch.next, 'b-next', (p) => `${p.name} ${p.chapter} ›`);
  }

  // On a wide screen the text sits in a column with margins either side; clicking in the margin
  // turns the page, the way you'd tap the edge of a book. A drag that selects text is left alone.
  // Watched as the click goes down rather than as it comes back up: by the time a click has
  // bubbled, whatever was open has already been closed by it, and the margin would read as bare
  // page and turn it.
  document.addEventListener('click', (e) => {
    if (window.innerWidth < 760 || e.target.closest('.b-sheet, .b-bar, .b-steps, .b-picker, .b-find, .b-versions, a, button')) {
      return;
    }
    // With something open, a click in the margin is how you dismiss it — not how you turn the page.
    if ($('.b-sheet') || $('.b-picker') || $('.b-find') || $('.b-versions') || $('.b-look')) {
      return;
    }
    // Only a real click of the pointer. Enter on a focused element (and anything a script
    // clicks) arrives with no coordinates at all, which would read as the far left margin.
    if (!e.detail) {
      return;
    }
    if ((window.getSelection()?.toString() || '').trim()) return;
    const box = page.getBoundingClientRect();
    const edge = e.clientX < box.left ? '.b-prev' : (e.clientX > box.right ? '.b-next' : '');
    if (!edge) return;
    const [book, chapter] = ($(edge)?.dataset.go || '').split(':');
    if (book) {
      e.preventDefault();
      turn(book, +chapter, edge === '.b-prev' ? 1 : -1);
    }
  }, true);

  document.addEventListener('click', (e) => {
    const a = e.target.closest('.b-steps a, [data-go]');
    if (!a) return;
    const [book, chapter] = (a.dataset.go || '').split(':');
    if (book) {
      e.preventDefault();
      go(book, +chapter);
    }
  });

  // Turning the page: the chapter follows your finger, springs back if you don't carry the turn
  // through, and slides away (with the next one sliding in) when you do.
  let x0 = null, y0 = null, dir = 0, dragging = false;
  const TURN = () => Math.min(110, window.innerWidth * 0.28);   // how far counts as a turn

  const target = (d) => {
    const link = $(d < 0 ? '.b-next' : '.b-prev');
    const [book, chapter] = (link?.dataset.go || '').split(':');
    return book ? { book, chapter: +chapter } : null;
  };
  const setX = (x, animate) => {
    const el = article();
    el.style.transition = animate ? 'transform .22s cubic-bezier(.22,.7,.3,1), opacity .22s' : '';
    el.style.transform = x ? `translateX(${x}px)` : '';
    el.style.opacity = x ? String(Math.max(0.35, 1 - Math.abs(x) / (window.innerWidth * 1.4))) : '';
  };

  page.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 1 || busy) { x0 = null; return; }
    x0 = e.touches[0].clientX;
    y0 = e.touches[0].clientY;
    dir = 0;
    dragging = false;
  }, { passive: true });

  page.addEventListener('touchmove', (e) => {
    if (x0 === null) return;
    const dx = e.touches[0].clientX - x0;
    const dy = e.touches[0].clientY - y0;
    if (!dragging) {
      if (Math.abs(dy) > Math.abs(dx)) { x0 = null; return; }    // a scroll, not a turn
      if (Math.abs(dx) < 12) return;
      dragging = true;
      dir = Math.sign(dx);
      closeNote();
    }
    // With no chapter that way (the ends of the Bible), the page barely moves.
    const resist = target(Math.sign(dx)) ? 1 : 0.22;
    setX(dx * resist, false);
  }, { passive: true });

  const endTurn = (e) => {
    if (x0 === null || !dragging) { x0 = null; return; }
    const dx = (e.changedTouches ? e.changedTouches[0].clientX : 0) - x0;
    x0 = null;
    dragging = false;
    const to = target(Math.sign(dx));
    if (!to || Math.abs(dx) < TURN()) {
      setX(0, true);                    // not far enough: spring back
      return;
    }
    turn(to.book, to.chapter, Math.sign(dx));
  };
  page.addEventListener('touchend', endTurn, { passive: true });
  page.addEventListener('touchcancel', () => { if (dragging) setX(0, true); x0 = null; dragging = false; }, { passive: true });

  // The finished turn: this page slides off, the new one slides in from the other side.
  async function turn(book, chapter, d) {
    if (busy) return;
    const el = article();
    const w = window.innerWidth;
    setX(d * w, true);
    await new Promise((r) => setTimeout(r, 180));
    await go(book, chapter, true, 0, true);
    const now = article();
    now.style.transition = '';
    now.style.transform = `translateX(${-d * w}px)`;
    now.style.opacity = '0.35';
    requestAnimationFrame(() => {
      now.style.transition = 'transform .24s cubic-bezier(.22,.7,.3,1), opacity .24s';
      now.style.transform = '';
      now.style.opacity = '';
    });
  }

  // Arrow keys on a computer.
  document.addEventListener('keydown', (e) => {
    if (e.target.matches('input, textarea')) return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
      const link = $(e.key === 'ArrowRight' ? '.b-next' : '.b-prev');
      const [book, chapter] = (link?.dataset.go || '').split(':');
      if (book) go(book, +chapter);
    } else if (e.key === 'Escape') {
      closeNote();
      $('.b-picker')?.remove();
    }
  });

  window.addEventListener('popstate', (e) => {
    const m = location.pathname.match(/read\/([A-Z0-9]{3})\/(\d+)/);
    if (m) go(m[1], +m[2], false);
  });

  // ---------- the book and chapter picker ----------
  $('.b-where').addEventListener('click', () => {
    const el = document.createElement('div');
    el.className = 'b-picker';
    el.innerHTML = `<header><input type="search" placeholder="Book" autocomplete="off" aria-label="Find a book">
        <button class="b-close" aria-label="Close">✕</button></header><div class="b-list"></div>`;
    document.body.appendChild(el);
    const list = $('.b-list', el);
    const draw = (filter = '') => {
      const f = filter.trim().toLowerCase().replace(/\s+/g, '');
      const match = (b) => !f || (b.name + b.abbrev + b.code).toLowerCase().replace(/\s+/g, '').includes(f);
      let html = '';
      // Where you've been lately, newest first: one tap back to any of them.
      // Six at most: more than two rows of chips pushes the books off a phone screen.
      const recent = (APP.recent || []).filter((r) => !f).slice(0, 6);
      if (recent.length) {
        html += '<div class="b-group">Recent</div><div class="b-recent">'
          + recent.map((r) => `<button class="b-chip" data-book="${r.book}" data-ch="${r.chapter}"`
            + `${r.version !== APP.version ? ` data-ver="${r.version}"` : ''}>${esc(r.name)} ${r.chapter}`
            + `${r.version !== APP.version ? ` <span class="b-chipv">${esc(r.version)}</span>` : ''}</button>`).join('')
          + '</div>';
      }
      for (const t of ['ot', 'nt']) {
        const books = APP.books.filter((b) => b.testament === t && match(b));
        if (!books.length) continue;
        html += `<div class="b-group">${t === 'ot' ? 'Old Testament' : 'New Testament'}</div>`;
        // Wrapped, so a wide screen can set the names in columns instead of one long list.
        html += '<div class="b-books">'
          + books.map((b) => `<button class="b-bk${b.code === APP.book ? ' on' : ''}" data-book="${b.code}">${esc(b.name)}</button>`).join('')
          + '</div>';
      }
      list.innerHTML = html || '<div class="b-group">Nothing by that name</div>';
    };
    draw();
    $('input', el).addEventListener('input', (ev) => draw(ev.target.value));
    $('input', el).focus();
    el.addEventListener('click', (ev) => {
      if (ev.target.closest('.b-close')) { remember(); el.remove(); return; }
      const chip = ev.target.closest('.b-chip');
      if (chip) {
        el.remove();
        if (chip.dataset.ver) {                    // read in another version: go there as it was
          location.href = `${APP.base}read/${chip.dataset.book}/${chip.dataset.ch}?v=${chip.dataset.ver}`;
        } else {
          go(chip.dataset.book, +chip.dataset.ch);
        }
        return;
      }
      const book = ev.target.closest('.b-bk')?.dataset.book;
      if (book) {
        const b = APP.books.find((x) => x.code === book);
        list.innerHTML = `<div class="b-group">${esc(b.name)}</div><div class="b-chapters">`
          + Array.from({ length: b.chapters }, (_, i) =>
            `<button data-chapter="${i + 1}"${book === APP.book && i + 1 === APP.chapter ? ' class="on"' : ''}>${i + 1}</button>`).join('')
          + '</div>';
        list.dataset.book = book;
        return;
      }
      const chapter = ev.target.closest('[data-chapter]')?.dataset.chapter;
      if (chapter) {
        el.remove();
        go(list.dataset.book, +chapter);
      }
    });
  });

  // ---------- search ----------
  // One box over as many translations as are ticked and whichever books are chosen. A reference
  // goes straight there; otherwise: bare words are all required and match by their beginning
  // ("descen" finds descendants), OR takes either, a leading - excludes, and "quoted words" must
  // appear in that order.
  //
  // The translations read from their publisher aren't searched: we hold no text of them to search.
  $('.b-search')?.addEventListener('click', () => {
    if ($('.b-find')) { $('.b-find').remove(); return; }
    const fetched = APP.fetched || [];
    const searchable = (APP.versions || []).filter((v) => !fetched.includes(v));
    if (!searchable.length) return;
    const reading = searchable.includes(APP.version);
    // What was ticked last time, if it still makes sense. Otherwise: the translation being read,
    // or — when that one can't be searched — all the ones that can.
    const saved = (Array.isArray(S.sv) ? S.sv : []).filter((v) => searchable.includes(v));
    const picked = new Set(saved.length ? saved : (reading ? [APP.version] : searchable));

    const el = document.createElement('div');
    el.className = 'b-find';
    el.innerHTML = `<header>
        <span class="b-find-box">
          <input type="search" placeholder="Word, &quot;phrase&quot; or reference" autocomplete="off" aria-label="Search the Bible">
          <button class="b-clear" hidden>Clear</button>
        </span>
        <button class="b-close" aria-label="Close">✕</button>
      </header>
      <div class="b-scopes b-vers">${searchable.map((v) =>
        `<button data-ver="${v}"${picked.has(v) ? ' class="on"' : ''}>${esc(v)}</button>`).join('')}</div>
      <div class="b-scopes">
        <button data-scope="all" class="on">Whole Bible</button>
        <button data-scope="ot">Old Testament</button>
        <button data-scope="nt">New Testament</button>
        <button data-scope="${APP.book}">This book</button>
        <button data-books>Books…</button>
      </div>
      <div class="b-books-pick" hidden></div>
      ${reading ? '' : `<p class="b-find-note">Searching ${esc(searchable.join(', '))}. `
        + `${esc(APP.version)} isn’t searched, so a word found here may not appear in it.</p>`}
      <div class="b-hits"></div>`;
    document.body.appendChild(el);
    const box = $('input', el);
    const hits = $('.b-hits', el);
    const pickBox = $('.b-books-pick', el);

    // Coming back to the box: the same question, asked again, at the place you had read down to.
    const remember = () => {
      S.find = { q: box.value.trim(), scope: Array.isArray(scope) ? [...scope] : scope, top: Math.round(hits.scrollTop) };
      applySettings();
    };
    let scrollTimer = null;
    hits.addEventListener('scroll', () => { clearTimeout(scrollTimer); scrollTimer = setTimeout(remember, 400); });
    let scope = 'all';          // 'all' | 'ot' | 'nt' | a book code | an array of book codes
    let chosen = new Set();     // books ticked in the Books… list
    let timer = null;
    let found = [];             // the hits as they came, so a name can swap the words below it
    let marking = [];           // the words to pick out in them
    const last = S.find || {};  // what was being looked for when the box was last closed

    const hint = () => `<p class="b-hint">Searching ${esc([...picked].join(', '))}.<br>`
      + `<span class="b-find-syntax">two words = both · <b>OR</b> = either · <b>-word</b> = without · `
      + `<b>"in this order"</b> · part of a word finds the rest of it</span></p>`;

    const mark = (text, words) => {
      let out = esc(text);
      for (const w of words || []) {
        if (w.length < 2) continue;
        out = out.replace(new RegExp(`(${w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\w*)`, 'gi'), '<mark>$1</mark>');
      }
      return out;
    };

    // The Books… list: every book, ticked in any combination.
    const drawBooks = () => {
      const group = (t, label) => `<div class="b-group">${label}</div><div class="b-chapters">`
        + (APP.books || []).filter((b) => b.testament === t)
          .map((b) => `<button data-pick="${b.code}"${chosen.has(b.code) ? ' class="on"' : ''}>${esc(b.abbrev)}</button>`).join('')
        + '</div>';
      pickBox.innerHTML = group('ot', 'Old Testament') + group('nt', 'New Testament')
        + `<div class="b-books-acts"><button data-pick-clear>Clear</button><button data-pick-done class="primary">Done</button></div>`;
    };

    const scopeLabel = () => {
      const b = el.querySelector('[data-books]');
      b.textContent = chosen.size ? `${chosen.size} book${chosen.size === 1 ? '' : 's'}` : 'Books…';
      b.classList.toggle('on', chosen.size > 0);
    };

    const run = async () => {
      const qs = box.value.trim();
      if (qs.length < 2) { hits.innerHTML = hint(); return; }
      hits.innerHTML = '<p class="b-hint">Looking…</p>';
      const sc = Array.isArray(scope) ? scope.join(',') : scope;
      try {
        const res = await fetch(`${APP.base}api/bible.php?action=search&vs=${[...picked].join(',')}`
          + `&scope=${encodeURIComponent(sc)}&q=${encodeURIComponent(qs)}`, { credentials: 'same-origin' });
        const data = await res.json();
        let html = '';
        if (data.goto) {
          html += `<button class="b-goto" data-x="${data.goto.book}:${data.goto.chapter}:${data.goto.verse}">`
            + `Go to <strong>${esc(data.goto.ref)}</strong></button>`;
        }
        if (data.hits?.length) {
          // One verse, one result. The wording shown to begin with is whichever translation uses
          // the words most often; the others it appears in are named beside it. Tapping a name
          // shows that one's wording here; tapping the wording opens the verse in whichever name
          // is lit.
          found = data.hits;
          marking = data.words || [];
          const many = picked.size > 1;
          html += `<p class="b-count">${data.total} verse${data.total === 1 ? '' : 's'}</p><ul class="b-xlist">`
            + data.hits.map((h, i) => `<li data-i="${i}">`
              + `<div class="b-xhead"><span class="b-xref">${esc(h.ref)}</span>`
              + (many ? `<span class="b-xvers">${(h.versions || [h.version]).map((v, n) =>
                  `<button data-gov="${v}" class="b-xver${n === 0 ? ' on' : ''}" `
                  + `title="Show ${esc(h.ref)} as ${esc(v)} has it">${esc(v)}</button>`).join('')}</span>` : '')
              + `</div><button class="b-xgo">${mark(h.text, data.words)}</button></li>`).join('')
            + '</ul>';
        } else if (!data.goto) {
          html += '<p class="b-hint">Nothing found.</p>';
        }
        hits.innerHTML = html;
      } catch (e) {
        hits.innerHTML = '<p class="b-hint">Couldn’t search just now.</p>';
      }
      remember();
    };

    // Empties the words without closing the box; the ✕ beside it does the closing.
    const clearBtn = $('.b-clear', el);
    const showClear = () => { clearBtn.hidden = box.value.trim() === ''; };

    hits.innerHTML = hint();
    box.addEventListener('input', () => { showClear(); clearTimeout(timer); timer = setTimeout(run, 250); });
    clearBtn.addEventListener('click', () => {
      box.value = '';
      showClear();
      clearTimeout(timer);
      found = [];
      hits.innerHTML = hint();
      remember();
      box.focus();
    });

    if (last.q) {
      box.value = last.q;
      if (Array.isArray(last.scope)) {
        chosen = new Set(last.scope);
        scope = [...chosen];
        scopeLabel();
        el.querySelectorAll('[data-scope]').forEach((x) => x.classList.remove('on'));
      } else if (last.scope && last.scope !== 'all') {
        scope = last.scope;
        const chip = el.querySelector(`[data-scope="${last.scope}"]`);
        el.querySelectorAll('[data-scope]').forEach((x) => x.classList.toggle('on', x === chip));
        if (!chip) { chosen = new Set([last.scope]); scope = [...chosen]; scopeLabel(); }
      }
      run().then(() => { hits.scrollTop = last.top || 0; });
    }
    showClear();
    box.focus();

    el.addEventListener('click', (ev) => {
      if (ev.target.closest('.b-close')) { el.remove(); return; }

      const ver = ev.target.closest('[data-ver]');
      if (ver) {
        const v = ver.dataset.ver;
        if (picked.has(v) && picked.size > 1) picked.delete(v); else picked.add(v);
        el.querySelectorAll('[data-ver]').forEach((b) => b.classList.toggle('on', picked.has(b.dataset.ver)));
        S.sv = [...picked];
        applySettings();                  // which keeps it here and in the account
        box.value.trim().length < 2 ? hits.innerHTML = hint() : run();
        return;
      }

      if (ev.target.closest('[data-books]')) {
        if (pickBox.hidden) { drawBooks(); pickBox.hidden = false; } else { pickBox.hidden = true; }
        return;
      }
      const pick = ev.target.closest('[data-pick]');
      if (pick) {
        const c = pick.dataset.pick;
        chosen.has(c) ? chosen.delete(c) : chosen.add(c);
        pick.classList.toggle('on', chosen.has(c));
        return;
      }
      if (ev.target.closest('[data-pick-clear]')) {
        chosen.clear();
        drawBooks();
        scope = 'all';
        el.querySelectorAll('[data-scope]').forEach((b) => b.classList.toggle('on', b.dataset.scope === 'all'));
        scopeLabel();
        run();
        return;
      }
      if (ev.target.closest('[data-pick-done]')) {
        pickBox.hidden = true;
        scope = chosen.size ? [...chosen] : 'all';
        el.querySelectorAll('[data-scope]').forEach((b) => b.classList.toggle('on', !chosen.size && b.dataset.scope === 'all'));
        scopeLabel();
        run();
        return;
      }

      const s = ev.target.closest('[data-scope]');
      if (s) {
        scope = s.dataset.scope;
        chosen.clear();
        scopeLabel();
        pickBox.hidden = true;
        el.querySelectorAll('[data-scope]').forEach((b) => b.classList.toggle('on', b === s));
        run();
        return;
      }

      const row = ev.target.closest('[data-i]');
      if (!row) return;
      const h = found[+row.dataset.i];
      if (!h) return;

      // A translation named beside the reference: show its wording here, and leave it at that.
      const badge = ev.target.closest('[data-gov]');
      if (badge) {
        const v = badge.dataset.gov;
        row.querySelectorAll('[data-gov]').forEach((x) => x.classList.toggle('on', x === badge));
        $('.b-xgo', row).innerHTML = mark((h.texts || {})[v] ?? h.text, marking);
        return;
      }
      // The wording: open the verse in whichever translation is lit above it.
      if (!ev.target.closest('.b-xgo')) return;
      const lit = row.querySelector('[data-gov].on')?.dataset.gov || h.version;
      el.remove();
      if (lit === APP.version) { go(h.book, h.chapter, true, h.verse); return; }
      location.href = `${APP.base}read/${h.book}/${h.chapter}?v=${lit}#v${h.verse}`;
    });
  });

  // ---------- sending the day's reading somewhere else ----------
  // Speechify, Notes, a message: whatever the phone's share sheet offers. The text goes as plain
  // words — no verse numbers, note markers or pilcrows, which a voice would read out as noise —
  // and carries the line naming whose translation it is, which the licensed ones require.
  function readingAsText() {
    const art = article();
    if (!art) return '';
    const copy = art.cloneNode(true);
    copy.querySelectorAll('.vn, .fn, .xr, .b-flag, .b-finish, .b-notice, .b-inline-note, .b-doneline')
      .forEach((x) => x.remove());
    const lines = [];
    for (const el of copy.children) {
      const words = el.textContent.replace(/¶/g, ' ').replace(/\s+/g, ' ').trim();
      if (!words) continue;
      if (el.tagName === 'H1' || el.tagName === 'H2') lines.push('', words, '');
      else if (/^H[3-6]$/.test(el.tagName)) lines.push('', words);
      else lines.push(words);
    }
    const notice = $('.b-notice')?.textContent.trim();
    lines.push('', APP.version + (notice ? ' — ' + notice : ''));
    return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-send-reading]');
    if (!btn) return;
    const text = readingAsText();
    if (!text) return;
    const title = $('.b-title')?.textContent.trim() || 'Reading';
    const say = (msg) => {
      const was = btn.textContent;
      btn.textContent = msg;
      setTimeout(() => { btn.textContent = was; }, 1800);
    };
    if (navigator.share) {
      try {
        await navigator.share({ title, text });
        return;
      } catch (err) {
        if (err && err.name === 'AbortError') return;      // the sheet was dismissed
      }
    }
    try {
      await navigator.clipboard.writeText(text);
      say('Copied — paste it in');
    } catch (err) {
      say('Couldn’t send it');
    }
  });

  // ---------- the version chooser ----------
  const closeVersions = () => $('.b-versions')?.remove();
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.b-versions, .b-version')) closeVersions();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeVersions(); });

  $('.b-version').addEventListener('click', () => {
    if ($('.b-versions')) { closeVersions(); return; }
    $('.b-bar').classList.remove('b-bar-away');   // so the menu has something to hang from
    const el = document.createElement('div');
    el.className = 'b-versions';
    el.innerHTML = APP.versions.map((v) => `<button data-version="${v}"${v === APP.version ? ' class="on"' : ''}>${v}</button>`).join('');
    document.body.appendChild(el);
    el.addEventListener('click', (ev) => {
      const v = ev.target.closest('[data-version]')?.dataset.version;
      if (!v) return;
      // Stay where you are, in the version chosen. In a day's reading that means the same day —
      // being thrown into the whole Bible loses the passage and the place at once. And the place
      // is carried as the verse at the top of the screen rather than as a distance down the page:
      // the same words take a different number of lines in a different translation.
      const where = APP.reading ? `reading/${APP.reading}` : `read/${APP.book}/${APP.chapter}`;
      location.href = `${APP.base}${where}?v=${v}${placeAnchor()}`;
    });
  });

  // The verse at the top of the screen, written so it can be found again in any translation.
  // A day's reading is several passages and the same verse number turns up in more than one of
  // them, so the passage is counted too.
  function placeAnchor() {
    const root = article();
    if (!root) return '';
    // At the top of the page, the place is the top of the page. Naming the first verse would land
    // just below the title, which is not where you were.
    if (window.scrollY < 80) return '';
    let passage = 0;
    for (const el of root.querySelectorAll('h2.b-ms, .v[data-v]')) {
      if (el.tagName === 'H2') { passage++; continue; }
      if (el.getBoundingClientRect().bottom > 80) return `#p${passage}v${el.dataset.v}`;
    }
    return '';
  }

  // A verse that opens a section belongs with its heading. Landing on the verse alone leaves the
  // heading just off the top of the screen, which drops you into the middle of a passage with no
  // sign of which one it is — so the heading is what to come back to.
  //
  // Not the page's own title, though: "Reading for Tuesday" and "John 3" head the whole page, and
  // coming back to one of those is coming back to the top, which is exactly what losing your place
  // looks like. Only headings within the text count.
  function landingFor(el) {
    const block = el.closest('p, div, li, blockquote') || el;
    if (block.querySelector('.v[data-v]') !== el) return el;     // not the first verse of its block
    let prev = block.previousElementSibling;
    while (prev && !prev.textContent.trim()) prev = prev.previousElementSibling;
    const heading = prev && /^H[2-6]$/.test(prev.tagName) && !prev.classList.contains('b-title');
    return heading ? prev : el;
  }

  // Coming back to where you were ("#p2v14"): the fourteenth verse of the second passage of a
  // day's reading, or simply verse 14 of a chapter, which has no passages and so counts as nought.
  // Put at the top of the screen rather than the middle, and not flashed — you were already
  // reading it, and nothing has happened that needs pointing out.
  function jumpToPlaceHash() {
    const m = /^#p(\d+)v(\d+)$/.exec(location.hash || '');
    const root = article();
    if (!m || !root) return false;
    let passage = 0;
    for (const el of root.querySelectorAll('h2.b-ms, .v[data-v]')) {
      if (el.tagName === 'H2') { passage++; continue; }
      if (passage === +m[1] && el.dataset.v === m[2]) {
        landingFor(el).scrollIntoView({ block: 'start' });
        window.scrollBy(0, -70);            // clear of the bar
        return true;
      }
    }
    return false;
  }

  // The back arrow goes where you actually came from, when that was somewhere in this app: the
  // topic that posted the reading, the day's reading you stepped into a chapter from, the search
  // you were looking at. Failing that — a fresh tab, a reload, a link from outside — it keeps the
  // page's own destination, which is the Scheduled Reading topic for a day's reading and the chat
  // for the whole Bible.
  //
  // Moving between pages of the same kind is not "coming from" anywhere: stepping through chapters,
  // or changing translation, would otherwise leave the arrow retracing every step.
  (() => {
    const back = $('.bar-back');
    if (!back) return;
    let from;
    try { from = new URL(document.referrer); } catch (e) { return; }
    if (from.origin !== location.origin || !from.pathname.startsWith(APP.base)) return;
    const kind = (path) => (/\/read\//.test(path) ? 'read' : /\/reading(\/|$)/.test(path) ? 'reading' : 'app');
    if (kind(from.pathname) === kind(location.pathname)) return;
    back.href = from.href;
  })();

  // Arriving from a link in a message ("…read/JHN/3#v16"): go to that verse and mark it briefly.
  function jumpToHash() {
    if (jumpToPlaceHash()) return;
    const m = /^#v(\d+)$/.exec(location.hash || '');
    if (!m) return;
    const target = article().querySelector(`.v[data-v="${m[1]}"]`);
    if (!target) return;
    target.scrollIntoView({ block: 'center' });
    target.classList.add('b-flash');
    setTimeout(() => target.classList.remove('b-flash'), 1800);
  }

  // The bar slides away as you read down, and comes back the moment you pull up, so a full screen
  // is text while you're reading and the book and chapter are a flick away when you want them.
  (() => {
    const bar = $('.b-bar');
    let last = window.scrollY;
    let hidden = false;
    const show = (yes) => {
      if (hidden === !yes) return;
      hidden = !yes;
      bar.classList.toggle('b-bar-away', hidden);
    };
    window.addEventListener('scroll', () => {
      const y = window.scrollY;
      if (y < 60) show(true);                     // at the top it's always there
      else if (y > last + 6) show(false);         // reading on
      else if (y < last - 6) show(true);          // pulling back up
      last = y;
    }, { passive: true });
    // Opening a menu or a sheet brings it back, so nothing is stranded off-screen.
    document.addEventListener('click', (e) => { if (e.target.closest('.b-bar, .b-sheet')) show(true); });
  })();

  // With no signal the page comes from the last one kept, whatever chapter that was; the chapter
  // the URL asks for is put back on the screen from this device's copy.
  const asked = /read\/([0-9A-Za-z]{3})\/(\d+)/.exec(location.pathname);
  if (asked && (asked[1].toUpperCase() !== APP.book || +asked[2] !== APP.chapter)) {
    go(asked[1].toUpperCase(), +asked[2], false);
  }

  applySettings();
  if (!location.hash) {
    goToPlace(APP.book, APP.chapter);
  }
  jumpToHash();
  applyMarks();
  loadMarks();
  renderDots();
  renderInlineNotes();
  markXrefs();
  prefetchNeighbors();   // the first chapter's links are rendered by bible.php; steps() redraws them later
  warmRecentReadings();

  // Keeps the past week's readings on this device, so a morning with no signal still has the reading
  // in it. Deliberately the last thing that happens: it runs only on a day's reading page, only once
  // that page and whatever the reader opened for it have finished, and then hands the list to the
  // service worker, which fetches one day at a time. Today's reading and the commentary someone
  // chose are already on the device by then — they were what put them there.
  function warmRecentReadings() {
    if (!APP.reading || !navigator.serviceWorker?.controller) return;
    const urls = [];
    for (let i = 1; i <= 7; i++) {
      const d = new Date(APP.reading + 'T12:00:00Z');
      d.setUTCDate(d.getUTCDate() - i);
      urls.push(`${APP.base}reading/${d.toISOString().slice(0, 10)}`);
    }
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 2000));
    idle(() => setTimeout(() => {
      navigator.serviceWorker.controller?.postMessage({ type: 'warm', urls });
    }, 3000));
  }
})();
