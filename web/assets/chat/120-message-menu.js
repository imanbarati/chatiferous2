  // The menu on a message (right-click, or hold on a phone): reply, quote, edit, delete, forward,
  // pin, copy and react.

  const msgById = (id) => state.view?.messages.find((m) => m.id === id);
  const canEdit = (m) => m.user_id === APP.me && m.kind === 'text' && (APP.admin || Date.now() - new Date(m.at) < EDIT_WINDOW_MS);
  const canDelete = (m) => (m.user_id === APP.me && m.kind !== 'service') || APP.admin;

  // The menu for one message, at the pointer or where the finger was held. What it offers
  // depends on the message: your own, someone else's, a poll, a conversation, an admin.
  function openMenu(m, x, y) {
    m = plainView(m);
    closeMenu();
    state.menuQuote = selectedQuote(m);
    const actions = [];
    if (m.kind !== 'service' && state.view.canPost) actions.push(['reply', '↩', 'Reply']);
    if (m.text && state.view.canPost) actions.push(['quote', '❝', state.menuQuote ? 'Quote selection' : 'Quote']);
    if (canEdit(m)) actions.push(['edit', '✎', 'Edit']);
    if (m.text) actions.push(['copy', '⧉', 'Copy text']);
    if (m.kind === 'text' || m.kind === 'poll') actions.push(['forward', '↪', 'Forward']);
    actions.push(['link', '🔗', 'Copy link']);
    if (APP.admin && m.kind !== 'service') actions.push(m.pinned ? ['unpin', '📌', 'Unpin'] : ['pin', '📌', 'Pin']);
    if (m.poll && !m.poll.closed && m.poll.voted) actions.push(['retract', '↺', 'Retract vote']);
    if (m.poll && !m.poll.closed && (m.user_id === APP.me || APP.admin)) actions.push(['closepoll', '■', 'Stop poll']);
    if (canDelete(m)) actions.push(['delete', '🗑', 'Delete']);

    const mine = (m.reactions || []).find((r) => r.mine)?.emoji;
    const reactRow = m.kind === 'service' ? '' : `<div class="menu-reactions">${QUICK.map((e) =>
      `<button data-emoji="${e}" class="${e === mine ? 'on' : ''}" aria-label="React ${e}">${e}</button>`).join('')}
      <button class="more-rx" aria-label="More reactions">⌄</button></div>`;
    const el = document.createElement('div');
    el.className = 'ctx-backdrop';
    el.innerHTML = `<div class="ctx" role="menu">${reactRow}<div class="menu-actions">${actions.map(([k, icon, label]) =>
      `<button data-act="${k}" role="menuitem" class="${k === 'delete' ? 'danger' : ''}"><span class="mi">${icon}</span>${label}</button>`).join('')}</div></div>`;
    document.body.appendChild(el);
    const menu = $('.ctx', el);
    if (!TOUCH && x !== undefined) {
      const r = menu.getBoundingClientRect();
      menu.style.left = Math.min(x, innerWidth - r.width - 8) + 'px';
      menu.style.top = Math.min(y, innerHeight - r.height - 8) + 'px';
      menu.classList.add('floating');
    }
    const row = $(`.msg[data-id="${m.id}"], .service[data-id="${m.id}"]`, chatEl);
    row?.classList.add('selected');
    el.addEventListener('click', (e) => {
      if (e.target === el) { closeMenu(); return; }
      if (e.target.closest('.more-rx')) {
        $('.menu-reactions', el).innerHTML = ALL_REACTIONS.map((r) =>
          `<button data-emoji="${r}" class="${r === mine ? 'on' : ''}" aria-label="React ${r}">${r}</button>`).join('');
        $('.menu-reactions', el).classList.add('all');
        return;
      }
      const emoji = e.target.closest('[data-emoji]')?.dataset.emoji;
      if (emoji) { closeMenu(); react(m.id, emoji); return; }
      const act = e.target.closest('[data-act]')?.dataset.act;
      if (act) { closeMenu(); doAction(act, m); }
    });
  }

  // Closes it and unmarks the message.
  function closeMenu() {
    document.querySelector('.ctx-backdrop')?.remove();
    chatEl.querySelector('.msg.selected, .service.selected')?.classList.remove('selected');
  }

  // Carries out the chosen item: reply, quote, edit, delete, forward, pin, copy or react.
  async function doAction(act, m) {
    try {
      if (act === 'reply') startReply(m);
      else if (act === 'edit') startEdit(m);
      else if (act === 'forward') openForwardPicker(m);
      else if (act === 'quote') { if (state.menuQuote) startReply(m, state.menuQuote); else openQuotePicker(m); }
      else if (act === 'copy') { await navigator.clipboard.writeText(m.text); toast('Copied.'); }
      else if (act === 'link') { await navigator.clipboard.writeText(`${location.origin}${APP.base}t/${state.view.topic.id}/${m.id}`); toast('Link copied.'); }
      else if (act === 'pin' || act === 'unpin') { await api('pin.php', { id: m.id, pin: act === 'pin' ? 1 : 0 }, true); scheduleSync(0); }
      else if (act === 'retract') await vote(m.id, []);
      else if (act === 'closepoll') {
        if (!confirm('Stop this poll? Nobody will be able to vote after that.')) return;
        upsert(await api('vote.php', { id: m.id, close: 1 }, true));
        renderMessages({ stay: true });
      } else if (act === 'delete') {
        if (!confirm('Delete this message for everyone?')) return;
        audio();   // wake the sound system while this still counts as your tap
        await api('delete.php', { id: m.id }, true);
        await dissolveMessage(m.id);
      }
    } catch (e) {
      toast(e.message);
    }
  }
