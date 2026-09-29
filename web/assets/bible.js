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
  const DEFAULTS = { font: 'serif', size: 3, theme: 'app', red: 0, numbers: 1, notes: 'markers', plain: 0, xrefs: 1 };
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
    b.style.setProperty('--b-size', SIZES[S.size]);
    b.style.setProperty('--b-lead', LEADS[S.size]);
    const label = (k, v) => { const el = $(`[data-val="${k}"]`); if (el) el.textContent = v; };
    label('font', { serif: 'Serif', system: 'System', legible: 'Hyperlegible' }[S.font]);
    label('theme', S.theme === 'paper' ? 'Paper' : 'App');
    label('red', S.red ? 'On' : 'Off');
    label('numbers', S.numbers ? 'On' : 'Off');
    label('notes', { markers: 'Markers', inline: 'In the text', off: 'Hidden' }[S.notes]);
    label('plain', S.plain ? 'On' : 'Off');
    label('xrefs', S.xrefs ? 'On' : 'Off');
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
  // chosen. Bible Hub stays at the foot of the sheet, for anything the app doesn't carry.
  const hubUrl = (verse) => {
    const el = article();
    return `https://biblehub.com/commentaries/${el.dataset.slug}/${el.dataset.chapter}-${verse}.htm`;
  };

  let works = [];      // the catalogue, as the server last sent it

  page.addEventListener('click', (e) => {
    const num = e.target.closest('.vn');
    if (!num) return;
    e.stopPropagation();      // or this same click would count as one outside the sheet it opens
    openCommentary(+num.dataset.v);
  });

  async function openCommentary(verse) {
    closeNote();
    const ref = `${article().querySelector('.b-title')?.textContent || ''}:${verse}`;
    const sheet = document.createElement('div');
    sheet.className = 'b-sheet b-comm';
    sheet.setAttribute('role', 'dialog');
    sheet.innerHTML = `<h3>${esc(ref)} · commentary</h3><p class="b-comm-wait">Looking…</p>`;
    document.body.appendChild(sheet);
    try {
      const res = await fetch(`${APP.base}api/commentary.php?b=${APP.book}&c=${APP.chapter}&verse=${verse}`,
        { credentials: 'same-origin' });
      const d = await res.json();
      if (!res.ok) throw new Error(d.error || 'Nothing here.');
      works = d.works || [];
      drawCommentary(sheet, ref, verse, d);
    } catch (err) {
      sheet.innerHTML = `<h3>${esc(ref)} · commentary</h3>
        <p class="b-comm-wait">The commentary couldn’t be fetched.</p>
        ${APP.biblehub ? `<p><a class="b-xref" href="${hubUrl(verse)}" target="_blank" rel="noopener">Look it up on Bible Hub</a></p>` : ''}`;
    }
  }

  function drawCommentary(sheet, ref, verse, d) {
    const here = works.filter((w) => w.here);
    const entries = d.entries || [];
    // A few works read best straight down the page; with a dozen or more, each one folds up so
    // the list of who has something to say fits on the screen.
    const fold = entries.length > 3;
    const body = entries.length
      ? entries.map((x) => `<details class="b-comm-entry"${fold ? '' : ' open'}>
          <summary><span class="b-comm-who">${esc(x.name)}</span><span>${esc(x.years || x.edition || '')}${x.verses !== String(verse) ? ` · verses ${esc(x.verses)}` : ''}</span></summary>
          ${x.html}
        </details>`).join('')
      : `<p class="b-comm-wait">${here.length
          ? 'None of the commentaries you’ve chosen has a note on this verse.'
          : 'No commentary has been imported for this chapter yet.'}</p>`;
    sheet.innerHTML = `<h3>${esc(ref)} · commentary</h3>
      <div class="b-comm-body">${body}</div>
      <div class="b-acts">
        <button data-comm="choose">Choose commentaries (${d.chosen.length})</button>
        ${APP.biblehub ? `<a class="b-comm-more" href="${hubUrl(verse)}" target="_blank" rel="noopener">More on Bible Hub</a>` : ''}
      </div>`;
    sheet.querySelector('[data-comm="choose"]')?.addEventListener('click', (ev) => {
      ev.stopPropagation();
      chooseWorks(verse, d.chosen);
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
            `<li><button data-x="${r.book}:${r.chapter}:${r.verse}"><span class="b-xref">${esc(r.ref)}</span>`
            + `<span class="b-xtext">${esc(r.text)}</span></button></li>`).join('') + '</ul>'
          : '<p>None for this verse.</p>');
    } catch (e) {
      sheet.innerHTML = '<p>Couldn’t fetch those just now.</p>';
    }
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
  const COLOURS = ['yellow', 'green', 'blue', 'pink', 'orange'];
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
          span.classList.add('b-hl', 'b-hl-' + (m.colour || 'yellow'));
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
  function mark(kind, verse, colour = '', body = '') {
    const book = APP.book, chapter = APP.chapter;
    const gone = marks.filter((m) => !(m.kind === kind && m.book === book && m.chapter === chapter
      && m.verse <= verse && m.end_verse >= verse));
    const add = { id: 'new-' + Date.now(), kind, book, chapter, verse, end_verse: verse, colour, body };
    marks = [...gone, add];
    rememberMarks();
    applyMarks();
    const payload = { kind, book, chapter, verse, end_verse: verse, colour, body, version: APP.version };
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
        ${COLOURS.map((c) => `<button class="b-sw b-sw-${c}${hl?.colour === c ? ' on' : ''}" data-hl="${c}" aria-label="Highlight ${c}"></button>`).join('')}
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
      const colour = e.target.closest('[data-hl]')?.dataset.hl;
      if (colour !== undefined) {
        if (colour) mark('highlight', +verse, colour); else unmark('highlight', +verse);
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
  page.addEventListener('touchstart', (e) => {
    const span = e.target.closest('.v');
    if (!span || e.touches.length !== 1) return;
    holdTimer = setTimeout(() => {
      holdTimer = null;
      swallowNextClick();
      openVerseActions(span);
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

  function offlineLabel() {
    const el = $('[data-val="offline"]');
    if (!el) return;
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
    if (have[APP.version]) {
      if (confirm(`Remove the copy of ${APP.version} kept on this device?`)) removeDownload(APP.version).catch(() => {});
    } else if (confirm(`Keep ${APP.version} on this device, so it reads with no signal? That's about 9 MB.`)) {
      download(APP.version);
    }
  });

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

  function prefetchNeighbours() {
    for (const link of [$('.b-prev'), $('.b-next')]) {
      const [book, chapter] = (link?.dataset.go || '').split(':');
      if (book) fetchChapter(book, +chapter).catch(() => {});
    }
  }

  let busy = false;
  async function go(book, chapter, push = true, verse = 0, turning = false) {
    if (busy) return;
    busy = true;
    try {
      const ch = await fetchChapter(book, chapter);
      const el = article();
      el.dataset.book = ch.book;
      el.dataset.chapter = ch.chapter;
      el.dataset.slug = ch.biblehub;
      el.dataset.notes = JSON.stringify(ch.notes);
      el.innerHTML = `<h1 class="b-title">${esc(ch.name)} ${ch.chapter}</h1>` + ch.html;
      APP.book = ch.book;
      APP.chapter = ch.chapter;
      $('.b-ref').textContent = `${ch.name} ${ch.chapter}`;
      document.title = `${ch.name} ${ch.chapter}`;
      steps(ch);
      prefetchNeighbours();
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
        window.scrollTo(0, 0);
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
      const recent = (APP.recent || []).filter((r) => !f);
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
        html += books.map((b) => `<button class="b-bk${b.code === APP.book ? ' on' : ''}" data-book="${b.code}">${esc(b.name)}</button>`).join('');
      }
      list.innerHTML = html || '<div class="b-group">Nothing by that name</div>';
    };
    draw();
    $('input', el).addEventListener('input', (ev) => draw(ev.target.value));
    $('input', el).focus();
    el.addEventListener('click', (ev) => {
      if (ev.target.closest('.b-close')) { el.remove(); return; }
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
  // One box: a reference goes straight there, "a phrase in quotes" matches exactly, and plain
  // words find the verses holding all of them. The scope buttons narrow it to a testament or the
  // book being read.
  $('.b-search')?.addEventListener('click', () => {
    if ($('.b-find')) { $('.b-find').remove(); return; }
    const el = document.createElement('div');
    el.className = 'b-find';
    el.innerHTML = `<header>
        <input type="search" placeholder="Word, &quot;phrase&quot; or reference" autocomplete="off" aria-label="Search the Bible">
        <button class="b-close" aria-label="Close">✕</button>
      </header>
      <div class="b-scopes">
        <button data-scope="all" class="on">Whole Bible</button>
        <button data-scope="ot">Old Testament</button>
        <button data-scope="nt">New Testament</button>
        <button data-scope="${APP.book}">This book</button>
      </div>
      <div class="b-hits"><p class="b-hint">Type to search ${esc(APP.version)}.</p></div>`;
    document.body.appendChild(el);
    const box = $('input', el);
    const hits = $('.b-hits', el);
    let scope = 'all';
    let timer = null;

    const mark = (text, words) => {
      let out = esc(text);
      for (const w of words || []) {
        if (w.length < 2) continue;
        out = out.replace(new RegExp(`(${w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi'), '<mark>$1</mark>');
      }
      return out;
    };

    const run = async () => {
      const qs = box.value.trim();
      if (qs.length < 2) { hits.innerHTML = `<p class="b-hint">Type to search ${esc(APP.version)}.</p>`; return; }
      hits.innerHTML = '<p class="b-hint">Looking…</p>';
      try {
        const res = await fetch(`${APP.base}api/bible.php?action=search&v=${APP.version}&scope=${scope}&q=${encodeURIComponent(qs)}`,
          { credentials: 'same-origin' });
        const data = await res.json();
        let html = '';
        if (data.goto) {
          html += `<button class="b-goto" data-x="${data.goto.book}:${data.goto.chapter}:${data.goto.verse}">`
            + `Go to <strong>${esc(data.goto.ref)}</strong></button>`;
        }
        if (data.hits?.length) {
          html += `<p class="b-count">${data.total} verse${data.total === 1 ? '' : 's'}</p><ul class="b-xlist">`
            + data.hits.map((h) => `<li><button data-x="${h.book}:${h.chapter}:${h.verse}">`
              + `<span class="b-xref">${esc(h.ref)}</span><span class="b-xtext">${mark(h.text, data.words)}</span></button></li>`).join('')
            + '</ul>';
        } else if (!data.goto) {
          html += '<p class="b-hint">Nothing found.</p>';
        }
        hits.innerHTML = html;
      } catch (e) {
        hits.innerHTML = '<p class="b-hint">Couldn’t search just now.</p>';
      }
    };

    box.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 250); });
    box.focus();
    el.addEventListener('click', (ev) => {
      if (ev.target.closest('.b-close')) { el.remove(); return; }
      const s = ev.target.closest('[data-scope]');
      if (s) {
        scope = s.dataset.scope;
        el.querySelectorAll('[data-scope]').forEach((b) => b.classList.toggle('on', b === s));
        run();
        return;
      }
      const go_to = ev.target.closest('[data-x]');
      if (go_to) {
        const [book, chapter, verse] = go_to.dataset.x.split(':');
        el.remove();
        go(book, +chapter, true, +verse);
      }
    });
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
      // Keep the place: same book and chapter in the version chosen.
      location.href = `${APP.base}read/${APP.book}/${APP.chapter}?v=${v}`;
    });
  });

  // Arriving from a link in a message ("…read/JHN/3#v16"): go to that verse and mark it briefly.
  function jumpToHash() {
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
  jumpToHash();
  applyMarks();
  loadMarks();
  renderDots();
  renderInlineNotes();
  markXrefs();
  prefetchNeighbours();   // the first chapter's links are rendered by bible.php; steps() redraws them later
})();
