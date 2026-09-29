  // Opening a topic: fetch its messages, draw them, and remember where you were. The heavy lifting
  // of drawing a message is in 060-link-previews.js (messageHtml and friends).

  // A note under a DM's header when its encryption needs something from someone.
  function dmNote(t, u) {
    const ks = t.keys || {}, name = esc(u.name || 'They');
    if (!ks.partner_key) return `<div class="dm-note">🔒 ${name} hasn’t set up private messages yet. They’ll be able to read what you send here once they do (next time they open the site).</div>`;
    if (ks.partner_needs === 'restore') return `<div class="dm-note">${name} set up private messages again (perhaps after losing their password), so they can’t read this conversation. <button class="link dm-restore">Restore their access</button></div>`;
    if (ks.has_key && !ks.lock) return `<div class="dm-note">🔒 Waiting for ${name} to give you access to this conversation. Their app shares it automatically, or asks them to, next time they’re on the site.</div>`;
    return '';
  }

  // The frame around a conversation: the top bar (with the pin bar and search), the scrolling
  // area, and the message box. Drawn once when a topic opens.
  // Everything the shell shows about a topic. The shell is drawn twice when a topic opens — once
  // from what the list already knew, again from what the server sent — and almost always the two
  // say exactly the same thing. Rebuilding it the second time throws away the message box along
  // with anything typed into it while the messages were on their way, so it is rebuilt only when
  // something on it has really changed.
  function shellSignature(t) {
    const u = t.kind === 'dm' ? (state.users[t.partner] || {}) : {};
    return JSON.stringify([t.id, t.kind || '', t.title || '', t.general || 0, t.emoji || '',
      t.color || 0, t.icon || '', t.partner || 0, t.i_blocked || 0,
      u.name || '', u.username || '', u.pending || 0]);
  }

  function chatShell(t) {
    const sig = shellSignature(t);
    if (chatEl.dataset.shell === sig && $('.messages', chatEl)) return;
    // When it must be redrawn for the same topic, what is half-written keeps its place and its
    // caret: a changed title is no reason to lose a sentence.
    const was = chatEl.dataset.shell && JSON.parse(chatEl.dataset.shell)[0] === t.id
      ? $('.composer textarea', chatEl) : null;
    const kept = was && was.value
      ? { value: was.value, start: was.selectionStart, end: was.selectionEnd, focused: document.activeElement === was }
      : null;
    chatEl.dataset.shell = sig;
    const dm = t.kind === 'dm', u = dm ? (state.users[t.partner] || { name: '' }) : null;
    const head = dm
      ? `<a class="bar-back" href="${APP.base}messages" aria-label="Back to messages">‹</a>
        <button class="who bar-who" data-user="${t.partner}">${avatar(t.partner, 'bar-av')}</button>
        <div class="bar-title who" data-user="${t.partner}"><div class="bar-name">${esc(u.name)}</div>`
        + `<div class="bar-sub">${u.pending ? 'hasn’t joined the new site yet' : '🔒 ' + (u.username ? '@' + esc(u.username) : 'end-to-end encrypted')}</div></div>`
      : `<a class="bar-back" href="${APP.base}" aria-label="Back to topics">‹</a>
        ${topicIcon(t, 'lg')}
        <div class="bar-title"><div class="bar-name">${esc(t.title)}</div><div class="bar-sub">${esc(APP.group)}</div></div>`;
    chatEl.innerHTML = `
      <header class="bar chat-bar">
        ${head}
        <button class="topic-menu" aria-label="${dm ? 'Chat menu' : 'Topic menu'}">⋮</button>
      </header>
      ${dm ? dmNote(t, u) : ''}
      <div class="pinbar-wrap" hidden><button class="pinbar"><span class="pinbar-label">Pinned message</span><span class="pinbar-text"></span></button><button class="pin-list" hidden aria-label="All pinned messages" title="All pinned messages">☰</button></div>
      <div class="scroll-wrap"><div class="float-date fade" aria-hidden="true"><span></span></div><div class="scroller"><div class="messages"></div></div></div>
      <button class="to-bottom" hidden aria-label="Scroll to the newest messages"><svg class="arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9.5l6 6 6-6"/></svg><span class="count" hidden></span></button>
      <button class="to-mention" hidden aria-label="Jump to a message that mentions you">@<span class="count"></span></button>
      <button class="to-reaction" hidden aria-label="Jump to a new reaction to your message">❤<span class="count"></span></button>
      <div class="compose-wrap">
        <div class="compose-bar" hidden><span class="cb-icon"></span><span class="cb-body"><span class="cb-title"></span><span class="cb-text"></span></span><button class="cb-cancel" aria-label="Cancel">✕</button></div>
        <div class="att-strip" hidden></div>
        <div class="mention-list" hidden role="listbox"></div>
        <footer class="composer">
          <button class="attach" aria-label="Attach a photo, file or poll">📎</button>
          <textarea rows="1" placeholder="Write a message…" aria-label="Message"></textarea>
          <button class="send" aria-label="Send" disabled>➤</button>
        </footer>
        <div class="closed-note" hidden>${!dm ? 'This topic is closed.'
          : t.i_blocked ? 'You’ve blocked this person. <button class="link dm-unblock">Unblock</button>' : 'You can’t message this person.'}</div>
      </div>
      <input type="file" class="file-input photo-input" multiple accept="image/jpeg,image/png,image/gif,image/webp" hidden>
      <input type="file" class="file-input any-input" multiple hidden>`;
    if (kept) {
      const ta = $('.composer textarea', chatEl);
      if (ta) {
        ta.value = kept.value;
        try { ta.setSelectionRange(kept.start, kept.end); } catch (e) { /* not focusable yet */ }
        if (kept.focused) ta.focus();
      }
    }
  }

  // One message: bubble, author, time, ticks, attachments and reactions. Whether it joins the
  // message above (same person, close in time) decides its shape and whether the name is repeated.
  function renderMessage(m, prev, next, v) {
    if (m.kind === 'service') return serviceHtml(m);
    const mine = m.user_id === APP.me;
    const u = state.users[m.user_id] || { name: 'Unknown', color: 0 };
    const joins = (a, b) => a && b && a.kind !== 'service' && b.kind !== 'service' && a.user_id === b.user_id
      && Math.abs(new Date(b.at) - new Date(a.at)) < GROUP_MS && sameDay(new Date(a.at), new Date(b.at))
      && !(v.firstUnread && b.id === v.firstUnread);
    const first = !joins(prev, m);
    const last = !joins(m, next);

    let body = '';
    const inDm = v.topic.kind === 'dm';   // two people: no names or pictures on the bubbles, as in Telegram
    if (first && !mine && !inDm) {
      body += `<div class="sender n${u.color % 7}" data-user="${m.user_id}">${esc(u.name)}${u.role ? `<span class="role">${u.role}</span>` : ''}</div>`;
    }
    if (m.fwd) {
      body += m.fwd_id
        ? `<button type="button" class="fwd fwd-link" data-fwd-topic="${m.fwd_topic}" data-fwd-id="${m.fwd_id}">Forwarded from <strong>${esc(m.fwd)}</strong></button>`
        : `<div class="fwd">Forwarded from <strong>${esc(m.fwd)}</strong></div>`;
    }
    if (m.reply) body += replyHtml(m.reply);
    for (const a of m.att || []) body += attachmentHtml(a);
    if (m.poll) body += pollHtml(m.poll);
    // In a DM, your messages show ✓ (sent) or ✓✓ (read), as in Telegram.
    const read = mine && v.topic.kind === 'dm' ? m.id <= (v.partnerRead || 0) : null;
    const ticks = read === null ? '' : `<span class="ticks${read ? ' read' : ''}" title="${read ? 'Read' : 'Sent'}">${read ? '✓✓' : '✓'}</span>`;
    const meta = `<span class="meta">${m.pinned ? '<span title="Pinned">📌</span>' : ''}${m.edited ? 'edited ' : ''}<time datetime="${m.at}" title="${esc(new Date(m.at).toLocaleString())}">${timeOf(new Date(m.at))}</time>${ticks}</span>`;
    // As in Telegram, the time sits at the end of the last line when there's room:
    // an invisible spacer the width of the time ends the text, and the time is
    // pinned to the bottom-right corner over it.
    if (m.text) body += `<div class="text">${formatText(m.text, m.ents)}${previewSlot(m)}${ticks ? '<span class="tick-space"></span>' : ''}<span class="meta-space${m.edited ? ' wide' : ''}${m.pinned ? ' pin' : ''}"></span>${meta}</div>`;
    else body += `<div class="text only-meta">${meta}</div>`;
    if (m.reactions) body += reactionsHtml(m.reactions);

    const photoOnly = !m.text && !m.poll && (m.att || []).every((a) => (a.kind === 'photo' || (a.kind === 'animation' && /^image\//.test(a.mime || ''))) && a.available) && m.att?.length;
    return `<div class="msg ${mine ? 'out' : 'in'} ${first ? 'first' : ''} ${last ? 'last' : ''}" data-id="${m.id}">`
      + (mine || inDm ? '' : (last ? `<button class="who" data-user="${m.user_id}" aria-label="${esc((state.users[m.user_id] || { name: '' }).name)}">${avatar(m.user_id)}</button>` : '<span class="avatar-space"></span>'))
      + `<div class="bubble ${photoOnly ? 'media' : ''}">${body}</div></div>`;
  }
