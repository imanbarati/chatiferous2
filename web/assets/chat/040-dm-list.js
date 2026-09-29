  // The Messages list: conversations, the picker for starting a new one, profile cards, ticks
  // (sent/read) and blocking, plus loadTopics()/loadDms(), which fetch what both lists show.
  // What's inside a conversation is the ordinary topic view.

  // A "Messages" row at the top of the topics opens your conversations, as Telegram keeps
  // private chats beside the group. ✎ there starts a new one; so does tapping someone's name.

  // The "Messages" row at the top of the topic list: total unread, and the latest conversation.
  function messagesRow() {
    const unread = state.dms.reduce((s, d) => s + (d.muted ? 0 : d.unread), 0);
    const last = state.dms[0];
    const who = last ? (state.users[last.partner] || { name: '' }).name : '';
    const preview = last
      ? `<span class="sender">${esc(who)}: </span>${last.last.mine ? 'You: ' : ''}${esc(last.last.preview)}`
      : 'Private conversations with other members';
    return `<a class="topic dm-folder" href="${APP.base}messages" data-folder="dms">`
      + `<span class="ticon dm-icon" aria-hidden="true">✉</span><span class="topic-main">`
      + `<span class="topic-row"><span class="topic-title">Messages</span><span class="topic-time">${last ? listTime(last.last.at) : ''}</span></span>`
      + `<span class="topic-row"><span class="topic-preview">${preview}</span>${unread ? `<span class="badge">${unread > 999 ? '999+' : unread}</span>` : ''}</span>`
      + `</span></a>`;
  }

  // The conversation rows: who, the last message (sealed ones say so), unread count and draft.
  function renderDmList() {
    const current = state.view?.topic.id;
    const rows = state.dms.map((d) => {
      const u = state.users[d.partner] || { name: '…' };
      const draft = d.id === current ? '' : draftFor(d.id);
      const preview = draft ? `<span class="draft-label">Draft: </span>${esc(draft.replace(/\s+/g, ' ').slice(0, 120))}`
        : (d.last.mine ? '<span class="sender">You: </span>' : '') + esc(d.last.preview);
      return `<a class="topic dm-row ${d.id === current ? 'active' : ''}" href="${APP.base}t/${d.id}" data-id="${d.id}">${avatar(d.partner, 'dm-av')}`
        + `<span class="topic-main"><span class="topic-row"><span class="topic-title">${esc(u.name)}${d.muted ? ' <span class="muted-icon" title="Muted">🔕</span>' : ''}</span>`
        + `<span class="topic-time">${listTime(d.last.at)}</span></span>`
        + `<span class="topic-row"><span class="topic-preview">${preview}</span>${d.unread ? `<span class="badge ${d.muted ? 'muted' : ''}">${d.unread}</span>` : ''}</span></span></a>`;
    }).join('');
    topicsEl.innerHTML = `<div class="dm-head"><button class="dm-back" aria-label="Back to topics">‹</button><strong>Messages</strong></div>`
      + (rows || '<p class="loading">No conversations yet. Tap ✎ to message someone, or tap a person’s name or picture in any topic.</p>');
  }

  // Switches between Topics and Messages, keeping the address bar in step.
  function showList(mode, push) {
    state.listMode = mode;
    if (push) history.pushState({}, '', APP.base + (mode === 'dms' ? 'messages' : ''));
    // On a wide screen the empty right-hand pane should name what this list holds.
    const empty = $('.chat-empty span');
    if (empty) empty.textContent = mode === 'dms' ? 'Select a conversation' : 'Select a topic';
    renderTopics();
  }

  // ✎ "New message", shown while the list shows conversations.
  function newDmButton() {
    let b = $('.new-dm');
    if (!b) {
      b = document.createElement('button');
      b.className = 'new-dm';
      b.setAttribute('aria-label', 'New message');
      // The same drawn pencil as the New topic button, for the same reason: the ✎ character comes
      // out thin and small, and differently on every platform.
      b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">'
        + '<path d="M16.8 3.2a2.7 2.7 0 0 1 3.8 3.8L8 19.6 3 21l1.4-5L16.8 3.2z"/>'
        + '<path d="M15.2 4.8l4 4"/></svg>';
      b.addEventListener('click', openNewDm);
      $('.pane-list').appendChild(b);
    }
    b.hidden = state.listMode !== 'dms';
    const nt = $('.new-topic');
    if (nt) nt.hidden = state.listMode === 'dms';
  }

  // Fetches the conversations, then shares or restores keys where a partner is waiting.
  async function loadDms() {
    try {
      const data = await api('dm.php');
      Object.assign(state.users, data.users || {});
      state.dms = data.dms;
      await openDmPreviews();
      renderTopics();
    } catch (e) { /* the topic list still works */ }
  }

  // Your conversation with someone (made if new), opened.
  async function startDm(userId) {
    try {
      const d = await api('dm.php', { user: userId }, true);
      document.querySelectorAll('.profile-modal, .newdm-modal').forEach((x) => x.remove());
      state.listMode = 'dms';
      openTopic(d.topic, 0, true);
    } catch (e) { toast(e.message); }
  }

  // A person's card: tap a name or picture. "Send message" opens your DM with them.
  function openProfile(id) {
    const u = state.users[id];
    if (!u || id === APP.me) return;
    const here = state.view?.topic.kind === 'dm' && state.view.topic.partner === id;
    const el = document.createElement('div');
    el.className = 'modal gif-modal profile-modal';
    el.innerHTML = `<div class="sheet-card profile-sheet" role="dialog" aria-label="${esc(u.name)}">
        <button class="link votes-close profile-close" aria-label="Close">✕</button>
        ${avatar(id, 'xl')}
        <h2>${esc(u.name)}</h2>
        <p class="muted">${[u.username ? '@' + esc(u.username) : '', u.role ? esc(u.role) : '', u.pending ? 'hasn’t joined the new site yet' : ''].filter(Boolean).join(' · ')}</p>
        ${!u.system && !here ? `<button class="primary dm-start" data-user="${id}">Send message</button>` : ''}
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', (e) => {
      if (e.target === el || e.target.closest('.profile-close')) { el.remove(); return; }
      if (e.target.closest('.dm-start')) startDm(id);
    });
  }

  // ✎: pick someone to message.
  async function openNewDm() {
    if (!state.members) {
      try { state.members = (await api('members.php')).members; } catch (e) { toast(e.message); return; }
    }
    const el = document.createElement('div');
    el.className = 'modal gif-modal newdm-modal';
    el.innerHTML = `<div class="sheet-card fwd-sheet" role="dialog" aria-label="New message">
        <div class="fwd-top"><strong>New message</strong><button class="link fwd-close" aria-label="Close">✕</button></div>
        <input type="search" class="newdm-q" placeholder="Search members" aria-label="Search members">
        <div class="fwd-list"></div>
      </div>`;
    document.body.appendChild(el);
    const list = $('.fwd-list', el), q = $('.newdm-q', el);
    const draw = () => {
      const s = q.value.trim().toLowerCase();
      list.innerHTML = state.members.filter((u) => u.id !== APP.me && (!s || u.name.toLowerCase().includes(s) || (u.username || '').toLowerCase().startsWith(s)))
        .slice(0, 60).map((u) => `<button class="fwd-target" data-user-pick="${u.id}">`
          + (u.photo ? `<img class="avatar dm-av" src="${esc(u.photo)}" alt="">` : `<span class="avatar c${u.color % 7} dm-av">${esc(initials(u.name))}</span>`)
          + `<span>${esc(u.name)}${u.username ? ` <span class="muted">@${esc(u.username)}</span>` : ''}</span></button>`).join('')
        || '<p class="loading">No one by that name.</p>';
    };
    draw();
    q.addEventListener('input', draw);
    if (!TOUCH) q.focus();
    el.addEventListener('click', (e) => {
      if (e.target === el || e.target.closest('.fwd-close')) { el.remove(); return; }
      const b = e.target.closest('[data-user-pick]');
      if (b) startDm(+b.dataset.userPick);
    });
  }

  // Fetches the topic list (and the sync cursor the live updates carry on from).
  async function loadTopics() {
    try {
      const data = await api('topics.php');
      state.topics = data.topics;
      if (!state.cursor) state.cursor = data.cursor;
      renderTopics();
      localSave('topics', data.topics);
    } catch (e) {
      // With no signal, the list this device last saw beats an error message where the topics were.
      if (e.name === 'NoSignal' && !state.topics.length) {
        const kept = await localLoad('topics');
        if (kept && kept.length) {
          state.topics = kept;
          renderTopics();
          return;
        }
      }
      if (!state.topics.length) topicsEl.innerHTML = `<p class="loading">${esc(e.message)}</p>`;
    }
  }
