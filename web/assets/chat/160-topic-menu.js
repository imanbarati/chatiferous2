  // The ⋮ menu at the top of a topic (mute, mark as read, search in topic, and, in a conversation,
  // block), and the pinned-messages list.

  chatEl.addEventListener('click', (e) => {
    if (!e.target.closest('.topic-menu') || !state.view) return;
    const v = state.view;
    if (v.topic.kind === 'dm') { dmMenu(e.target.closest('.topic-menu')); return; }
    const t = state.topics.find((x) => x.id === v.topic.id) || {};
    closeMenu();
    const el = document.createElement('div');
    el.className = 'ctx-backdrop';
    el.innerHTML = `<div class="ctx" role="menu"><div class="menu-actions">
      <button data-tact="mute" role="menuitem"><span class="mi">${t.muted ? '🔔' : '🔕'}</span>${t.muted ? 'Unmute' : 'Mute'} this topic</button>
      <button data-tact="read" role="menuitem"><span class="mi">✓</span>Mark as read</button>
      <button data-tact="search" role="menuitem"><span class="mi">🔍</span>Search in this topic</button>
      ${canManage(t) ? `<button data-tact="edit" role="menuitem"><span class="mi">✎</span>Edit topic</button>` : ''}
      ${canManage(t) && !t.general ? `<button data-tact="close" role="menuitem"><span class="mi">${t.closed ? '🔓' : '🔒'}</span>${t.closed ? 'Reopen topic' : 'Close topic'}</button>` : ''}
      ${APP.admin ? `<button data-tact="pin" role="menuitem"><span class="mi">📌</span>${t.pinned ? 'Unpin from top' : 'Pin to top'}</button>` : ''}
      ${canManage(t) && !t.general ? `<button data-tact="delete" role="menuitem" class="danger"><span class="mi">🗑</span>Delete topic</button>` : ''}</div></div>`;
    document.body.appendChild(el);
    {
      // Drops down from the ⋮ button on phones too, as in Telegram (not a bottom sheet).
      const r = e.target.closest('.topic-menu').getBoundingClientRect(), menu = $('.ctx', el);
      menu.classList.add('floating');
      menu.style.left = Math.max(8, r.right - menu.getBoundingClientRect().width) + 'px';
      menu.style.top = (r.bottom + 4) + 'px';
    }
    el.addEventListener('click', async (ev) => {
      const act = ev.target.closest('[data-tact]')?.dataset.tact;
      el.remove();
      try {
        if (act === 'mute') {
          await api('mute.php', { topic: v.topic.id, muted: t.muted ? 0 : 1 }, true);
          toast(t.muted ? 'Notifications on for this topic.' : 'Muted: no notifications from this topic.');
          loadTopics();
        } else if (act === 'search') {
          openSearch(t);
        } else if (act === 'edit') {
          topicDialog(t);
        } else if (act === 'close') {
          await api('topic.php', { action: 'close', id: t.id, closed: t.closed ? 0 : 1 }, true);
          scheduleSync(0);
        } else if (act === 'pin') {
          await api('topic.php', { action: 'pin', id: t.id, pin: t.pinned ? 0 : 1 }, true);
          loadTopics();
        } else if (act === 'delete') {
          if (!confirm(`Delete the topic “${t.title}” and every message in it? This can’t be undone.`)) return;
          await api('topic.php', { action: 'delete', id: t.id }, true);
          closeTopic(true);
          toast('Topic deleted.');
        } else if (act === 'read') {
          const last = t.last?.id || 0;
          if (last) await api('read.php', { topic: v.topic.id, message: last }, true);
          v.lastRead = Math.max(v.lastRead, last);
          v.mentions = [];
          renderMentionButton();
          loadTopics();
        }
      } catch (err) {
        toast(err.message);
      }
    });
  });

  // A DM's ⋮: mute, mark read, search, block.
  function dmMenu(anchor) {
    const v = state.view, d = state.dms.find((x) => x.id === v.topic.id) || {};
    const u = state.users[v.topic.partner] || { name: '' };
    closeMenu();
    const el = document.createElement('div');
    el.className = 'ctx-backdrop';
    el.innerHTML = `<div class="ctx floating" role="menu"><div class="menu-actions">
      <button data-dact="mute" role="menuitem"><span class="mi">${d.muted ? '🔔' : '🔕'}</span>${d.muted ? 'Unmute' : 'Mute'}</button>
      <button data-dact="read" role="menuitem"><span class="mi">✓</span>Mark as read</button>
      <button data-dact="search" role="menuitem"><span class="mi">🔍</span>Search in this chat</button>
      <button data-dact="block" role="menuitem" class="${v.topic.i_blocked ? '' : 'danger'}"><span class="mi">⛔</span>${v.topic.i_blocked ? 'Unblock' : 'Block'} ${esc(u.name)}</button></div></div>`;
    document.body.appendChild(el);
    const r = anchor.getBoundingClientRect(), menu = $('.ctx', el);
    menu.style.left = Math.max(8, r.right - menu.getBoundingClientRect().width) + 'px';
    menu.style.top = (r.bottom + 4) + 'px';
    el.addEventListener('click', async (ev) => {
      const act = ev.target.closest('[data-dact]')?.dataset.dact;
      el.remove();
      try {
        if (act === 'mute') {
          await api('mute.php', { topic: v.topic.id, muted: d.muted ? 0 : 1 }, true);
          toast(d.muted ? 'Notifications on for this chat.' : 'Muted: no notifications from this chat.');
          loadDms();
        } else if (act === 'read') {
          const last = v.messages[v.messages.length - 1]?.id || 0;
          if (last) await api('read.php', { topic: v.topic.id, message: last }, true);
          loadDms();
        } else if (act === 'search') {
          openSearch({ ...v.topic, title: u.name });
        } else if (act === 'block') {
          await setBlock(v.topic.partner, !v.topic.i_blocked);
        }
      } catch (err) { toast(err.message); }
    });
  }

  // ⋮ → Message recovery code: a fresh code (the old one stops working).
  document.addEventListener('click', async (e) => {
    if (!e.target.closest('[data-new-code]')) return;
    try {
      if (!(await e2eReady())) return;
      if (!confirm('Make a new recovery code? Your old code will stop working.')) return;
      const r = await E2E.relockWithNewCode(e2e.priv);
      await api('keys.php', { action: 'relock', locked_code: r.lockedByCode }, true);
      (await keyRecord()).locked_code = r.lockedByCode;
      showRecoveryCode(r.code, false);
    } catch (err) { toast(err.message); }
  });

  // Blocks or unblocks the other person in a conversation, after asking.
  async function setBlock(userId, on) {
    const u = state.users[userId] || { name: 'this person' };
    if (on && !confirm(`Block ${u.name}? They won’t be able to send you direct messages.`)) return;
    await api('dm.php', { block: userId, on: on ? 1 : 0 }, true);
    toast(on ? `${u.name} is blocked.` : `${u.name} is unblocked.`);
    if (state.view?.topic.kind === 'dm') openTopic(state.view.topic.id);   // refresh what you can do
  }
