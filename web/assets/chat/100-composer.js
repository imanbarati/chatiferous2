  // The message box: typing, drafts (kept per topic), autosizing, sending, editing, replying, and
  // quote-replies (select text, or pick words in a sheet on a phone).

  // Sets up the message box for a freshly opened topic: the saved draft, the right height, and
  // whether posting is allowed at all (a closed topic, or someone who has blocked you).
  function initComposer() {
    const v = state.view;
    const ta = box();
    $('.composer', chatEl).hidden = !v.canPost;
    $('.closed-note', chatEl).hidden = v.canPost;
    if (!v.canPost) return;
    state.compose = null;
    state.uploads = [];
    state.mentionPicks = [];
    state.topicPicks = [];
    try {
      ta.value = localStorage.getItem('draft:' + v.topic.id) || '';
    } catch (e) { /* storage unavailable */ }
    autosize();
    updateSendButton();
    ta.addEventListener('input', () => {
      autosize(); updateSendButton(); mentionSearch();
      clearTimeout(state.draftTimer);
      state.draftTimer = setTimeout(saveDraft, 400);
    });
    ta.addEventListener('keydown', composerKey);
    ta.addEventListener('paste', (e) => {
      const files = [...(e.clipboardData?.files || [])];
      if (files.length) { e.preventDefault(); files.forEach(addUpload); }
    });
    if (!TOUCH) ta.focus();
  }

  // Keeps what you've typed, per topic, so leaving and coming back loses nothing. Not while
  // editing: that text belongs to the message, not to the topic.
  function saveDraft() {
    const v = state.view, ta = box();
    if (!v || !ta || state.compose?.mode === 'edit') return;
    clearTimeout(state.draftTimer);
    const had = draftFor(v.topic.id);
    try {
      if (ta.value.trim()) localStorage.setItem('draft:' + v.topic.id, ta.value);
      else localStorage.removeItem('draft:' + v.topic.id);
    } catch (e) { /* storage unavailable */ }
    if (had !== draftFor(v.topic.id)) renderTopics();
  }

  // The saved draft for a topic, for the preview in the list.
  function draftFor(id) {
    try { return (localStorage.getItem('draft:' + id) || '').trim(); } catch (e) { return ''; }
  }

  // Grows the box with what you write, up to a few lines, then lets it scroll.
  function autosize() {
    const ta = box();
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, window.innerHeight * 0.4) + 'px';
  }

  // Send is shown when there's something to send; otherwise the attach and GIF buttons are.
  function updateSendButton() {
    const ta = box(), btn = $('.composer .send', chatEl);
    if (!ta || !btn) return;
    const uploading = state.uploads.some((u) => u.status === 'uploading');
    const ready = state.uploads.some((u) => u.status === 'done');
    btn.disabled = uploading || (!ta.value.trim() && !ready && state.compose?.mode !== 'forward');
  }

  // Keys in the message box: Enter sends, Shift+Enter makes a line, ↑ edits your last message,
  // Escape cancels. While the @ or ~ picker is open, the arrows and Enter belong to it.
  function composerKey(e) {
    const list = $('.mention-list', chatEl);
    if (!list.hidden && ['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(e.key)) {
      mentionKey(e);
      return;
    }
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && (!TOUCH || e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      submit();
    } else if (e.key === 'Escape' && state.compose) {
      cancelCompose();
    } else if (e.key === 'ArrowUp' && !box().value && !state.compose) {
      // Up arrow in an empty box edits your last message, as in Telegram.
      const mine = [...state.view.messages].reverse().find((m) => m.user_id === APP.me && canEdit(m));
      if (mine) { e.preventDefault(); startEdit(mine); }
    }
  }

  // The strip above the box that says what you're replying to, quoting or editing.
  function setBar(icon, title, text) {
    const bar = $('.compose-bar', chatEl);
    bar.hidden = false;
    $('.cb-icon', bar).textContent = icon;
    $('.cb-title', bar).textContent = title;
    $('.cb-text', bar).textContent = text;
  }

  // Begins a reply (with the quoted words, if some were picked).
  function startReply(m, quote = '') {
    if (!state.view.canPost) return;
    state.compose = { mode: 'reply', message: m, quote };
    const u = state.users[m.user_id] || { name: '' };
    if (quote) setBar('❝', 'Quote from ' + u.name, quote.replace(/\s+/g, ' '));
    else setBar('↩', 'Reply to ' + u.name, previewOf(m));
    box().focus();
  }

  // ---------- Quote-reply ----------
  // Answer just part of a message, as in Telegram: the reply's header shows those words.
  const QUOTE_MAX = 1024;

  // Text selected (on a computer) inside this message, if it's really part of its text.
  function selectedQuote(m) {
    const sel = window.getSelection();
    if (!sel || sel.isCollapsed || !m.text) return '';
    const el = chatEl.querySelector(`.msg[data-id="${m.id}"] .text`);
    if (!el || !el.contains(sel.anchorNode) || !el.contains(sel.focusNode)) return '';
    const t = sel.toString().trim().slice(0, QUOTE_MAX).trim();
    return t && m.text.includes(t) ? t : '';
  }

  // Phones (and nothing selected): show the text in a sheet where words can be selected.
  function openQuotePicker(m) {
    const u = state.users[m.user_id] || { name: '' };
    const el = document.createElement('div');
    el.className = 'modal gif-modal';
    el.innerHTML = `<div class="sheet-card quote-sheet" role="dialog" aria-label="Quote">
        <div class="fwd-top"><strong>Quote from ${esc(u.name)}</strong><button class="link quote-close" aria-label="Close">✕</button></div>
        <p class="small muted">Select the words you’re answering, or quote the whole message.</p>
        <div class="quote-src" tabindex="0"></div>
        <button class="primary quote-go">Quote whole message</button>
      </div>`;
    document.body.appendChild(el);
    const src = $('.quote-src', el), go = $('.quote-go', el);
    src.textContent = m.text;
    const picked = () => {
      const sel = window.getSelection();
      if (!sel || sel.isCollapsed || !src.contains(sel.anchorNode) || !src.contains(sel.focusNode)) return '';
      return sel.toString().trim();
    };
    const onSel = () => { go.textContent = picked() ? 'Quote selection' : 'Quote whole message'; };
    document.addEventListener('selectionchange', onSel);
    const close = () => { document.removeEventListener('selectionchange', onSel); el.remove(); };
    el.addEventListener('click', (e) => {
      if (e.target === el || e.target.closest('.quote-close')) { close(); return; }
      if (!e.target.closest('.quote-go')) return;
      const q = (picked() || m.text.trim()).slice(0, QUOTE_MAX).trim();
      close();
      startReply(m, q);
    });
  }

  // Begins editing: the message's own text and formatting go back into the box.
  function startEdit(m) {
    const [text, picks] = toMarkdown(m.text, m.ents || []);
    state.compose = { mode: 'edit', message: m, saved: box().value };
    state.mentionPicks = picks;
    box().value = text;
    setBar('✎', 'Edit message', previewOf(m));
    autosize();
    updateSendButton();
    box().focus();
  }

  // Forward: choose a topic, then it opens with a "Forward message" bar above the box, so you
  // can add a comment (sent first) before sending, as in Telegram.
  function openForwardPicker(m) {
    const fromTopic = state.view.topic;
    const targets = state.topics.filter((t) => !t.closed || APP.admin || t.created_by === APP.me);
    const el = document.createElement('div');
    el.className = 'modal gif-modal';
    el.innerHTML = `<div class="sheet-card fwd-sheet" role="dialog" aria-label="Forward to">
        <div class="fwd-top"><strong>Forward to…</strong><button class="link fwd-close" aria-label="Close">✕</button></div>
        <div class="fwd-list">${targets.map((t) => `<button class="fwd-target" data-id="${t.id}">${topicIcon(t)}<span>${esc(t.title)}</span></button>`).join('')}
          ${state.dms.length ? '<div class="fwd-label">People</div>' + state.dms.map((d) => `<button class="fwd-target" data-id="${d.id}">${avatar(d.partner, 'dm-av')}<span>${esc((state.users[d.partner] || { name: '' }).name)}</span></button>`).join('') : ''}</div>
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', async (e) => {
      if (e.target === el || e.target.closest('.fwd-close')) { el.remove(); return; }
      const b = e.target.closest('.fwd-target');
      if (!b) return;
      el.remove();
      await openTopic(+b.dataset.id, 0, true);
      if (!state.view || !state.view.canPost) return;
      state.compose = { mode: 'forward', message: m, fromTopic };
      const u = state.users[m.user_id] || { name: '' };
      setBar('↪', 'Forward message', (m.fwd || u.name) + ': ' + previewOf(m));
      updateSendButton();
      if (!TOUCH) box().focus();
    });
  }

  // A verse sent over from the reader ("Quote in the chat"): ask which topic, then put it in the
  // message box as a quotation with its reference, ready to send or add to.
  function pendingBibleQuote() {
    let q = null;
    try {
      const raw = sessionStorage.getItem('bible-quote');
      if (!raw) return;
      sessionStorage.removeItem('bible-quote');
      q = JSON.parse(raw);
    } catch (e) { return; }
    if (!q?.text) return;
    const targets = state.topics.filter((t) => !t.closed || APP.admin || t.created_by === APP.me);
    const el = document.createElement('div');
    el.className = 'modal gif-modal';
    el.innerHTML = `<div class="sheet-card fwd-sheet" role="dialog" aria-label="Send this verse to">
        <div class="fwd-top"><strong>${esc(q.ref)} — send to…</strong><button class="link fwd-close" aria-label="Close">✕</button></div>
        <div class="fwd-list">${targets.map((t) => `<button class="fwd-target" data-id="${t.id}">${topicIcon(t)}<span>${esc(t.title)}</span></button>`).join('')}
          ${state.dms.length ? '<div class="fwd-label">People</div>' + state.dms.map((d) => `<button class="fwd-target" data-id="${d.id}">${avatar(d.partner, 'dm-av')}<span>${esc((state.users[d.partner] || { name: '' }).name)}</span></button>`).join('') : ''}</div>
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', async (e) => {
      if (e.target === el || e.target.closest('.fwd-close')) { el.remove(); return; }
      const b = e.target.closest('.fwd-target');
      if (!b) return;
      el.remove();
      await openTopic(+b.dataset.id, 0, true);
      if (!state.view?.canPost) { toast('You can’t post in there.'); return; }
      const ta = box();
      // "> " makes a quotation; the reference follows underneath.
      ta.value = (ta.value ? ta.value.replace(/\s*$/, '\n\n') : '')
        + q.text.split('\n').map((l) => '> ' + l).join('\n') + `\n— ${q.ref} (${q.version})\n`;
      autosize();
      updateSendButton();
      ta.focus();
      ta.setSelectionRange(ta.value.length, ta.value.length);
    });
  }

  // Drops the reply, quote or edit and puts back whatever you had been typing.
  function cancelCompose() {
    if (state.compose?.mode === 'edit') { box().value = state.compose.saved || ''; autosize(); }
    state.compose = null;
    $('.compose-bar', chatEl).hidden = true;
    updateSendButton();
  }

  // A one-line version of a message for the bar above the box and for the list.
  function previewOf(m) {
    if (m.poll) return '📊 ' + m.poll.question;
    if (m.text) return m.text.replace(/\s+/g, ' ').slice(0, 120);
    const a = (m.att || [])[0];
    return a ? (a.kind === 'photo' ? '🖼 Photo' : a.kind === 'animation' ? 'GIF' : '📎 ' + (a.name || 'File')) : '';
  }

  // Stored text + entities back into what you'd type, for editing.
  function toMarkdown(text, ents) {
    const picks = [];
    const marks = [];
    for (const e of ents) {
      const end = e.offset + e.length;
      const wrap = { bold: '**', italic: '__', strike: '~~', spoiler: '||', code: '`' }[e.type];
      if (wrap) { marks.push([e.offset, wrap, 1], [end, wrap, 0]); }
      else if (e.type === 'pre') { marks.push([e.offset, '```' + (e.language || '') + '\n', 1], [end, '\n```', 0]); }
      else if (e.type === 'text_link') { marks.push([e.offset, '[', 1], [end, `](${e.url})`, 0]); }
      else if (e.type === 'mention_name') {
        marks.push([e.offset, '@', 1]);
        picks.push({ user_id: e.user_id, name: text.slice(e.offset, end) });
      } else if (e.type === 'blockquote') {
        marks.push([e.offset, '> ', 1]);
        for (let i = e.offset; i < end; i++) if (text[i] === '\n') marks.push([i + 1, '> ', 1]);
      }
    }
    // Insert from the end so earlier offsets stay valid; closers before openers at the same spot.
    marks.sort((a, b) => b[0] - a[0] || b[2] - a[2]);
    let out = text;
    for (const [pos, str] of marks) out = out.slice(0, pos) + str + out.slice(pos);
    return [out, picks];
  }

  // Sends what's in the box: a new message, an edit, or a reply, with anything attached. In a
  // conversation everything is sealed here first; the view is then held steady (readingSpot).
  async function submit() {
    const v = state.view, ta = box();
    const text = withTopicLinks(ta.value);
    const done = state.uploads.filter((u) => u.status === 'done');
    if (!text.trim() && !done.length && state.compose?.mode !== 'forward') return;
    const btn = $('.composer .send', chatEl);
    btn.disabled = true;
    const mentions = JSON.stringify(state.mentionPicks.filter((p) => text.includes('@' + p.name)));
    const dm = v.topic.kind === 'dm';
    const fwd = state.compose?.mode === 'forward' ? state.compose : null;
    try {
      if (fwd && fwd.message._p) {   // forwarding out of a DM: only this device can read it, so it re-sends it
        audio();
        const data = await forwardFromDm(fwd.message, fwd.fromTopic, v.topic, text);
        playToc();
        ta.value = '';
        state.compose = null;
        $('.compose-bar', chatEl).hidden = true;
        upsert(data);
        v.firstUnread = 0;
        renderMessages();
        scheduleSync(300);
        return;
      }
      if (state.compose?.mode === 'edit') {
        let data;
        if (dm) {   // re-sealed with its files and quote as they were
          const orig = state.compose.message._p || {};
          const { payload, sealed } = await sealTyped(v, text, { ...(orig.q ? { q: orig.q } : {}), ...(orig.a ? { a: orig.a } : {}) });
          data = await api('edit.php', { id: state.compose.message.id, text: sealed }, true);
          data.message._p = payload;
        } else {
          data = await api('edit.php', { id: state.compose.message.id, text, mentions }, true);
        }
        upsert(data);
        ta.value = state.compose.saved || '';
        state.compose = null;
        $('.compose-bar', chatEl).hidden = true;
      } else {
        const params = { topic: v.topic.id, text, mentions, attachments: done.map((u) => u.id).join(',') };
        if (state.compose?.mode === 'reply') params.reply_to = state.compose.message.id;
        if (state.compose?.mode === 'reply' && state.compose.quote) params.quote = state.compose.quote;
        if (state.compose?.mode === 'forward') { params.forward = state.compose.message.id; params.attachments = ''; }
        let payload = null;
        if (dm) {   // sealed on this device: text, formatting, quote and file details
          const files = done.map((u) => u.meta).filter(Boolean);
          if (fwd) {
            const src = fwd.message;
            params.sealed = (await sealTyped(v, '', {}, { t: src.text || '', e: src.ents || [] })).sealed;
            params.text = text.trim() ? (await sealTyped(v, text)).sealed : '';
          } else {
            const sealedTyped = await sealTyped(v, text, {
              ...(state.compose?.mode === 'reply' && state.compose.quote ? { q: state.compose.quote } : {}),
              ...(files.length ? { a: files } : {}),
            });
            payload = sealedTyped.payload;
            params.text = sealedTyped.sealed;
          }
          params.quote = '';
          params.mentions = '[]';
        }
        audio();
        const ret = readingSpot();
        const data = await api('send.php', params, true);
        if (payload && data.message) data.message._p = payload;
        playToc();
        state.returnPoint = ret && data.message ? { ...ret, topic: v.topic.id, sentId: data.message.id } : null;
        for (const extra of data.also || []) upsert(extra);   // the comment sent with a forward
        ta.value = '';
        state.compose = null;
        $('.compose-bar', chatEl).hidden = true;
        clearUploads();
        clearTimeout(state.draftTimer);
        try { localStorage.removeItem('draft:' + v.topic.id); } catch (e) { /* ignore */ }
        if (v.hasNewer) {
          const page = await api('messages.php', { topic: v.topic.id, mode: 'bottom' });
          applyPage(page, 'replace');
          v.firstUnread = 0;
        } else {
          upsert(data);
        }
        v.firstUnread = 0;
        renderMessages();
        scheduleSync(300);
        return;
      }
      state.mentionPicks = [];
      renderMessages({ stay: true });
    } catch (e) {
      toast(e.message);
    } finally {
      autosize();
      updateSendButton();
    }
  }
