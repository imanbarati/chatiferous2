  // Live updates: a short poll of api/sync.php for new and changed messages, reactions, read
  // receipts, DMs and key changes. Cheap on shared hosting, and it backs off when the tab is hidden.

  // Books the next check for changes: often while a topic is open, less often on the list, and
  // not at all while the tab is hidden.
  function scheduleSync(delay) {
    clearTimeout(state.syncTimer);
    if (document.hidden) return;
    state.syncTimer = setTimeout(sync, delay ?? (state.view ? SYNC_OPEN_MS : SYNC_LIST_MS));
  }

  // One round of catching up: sends the cursor (where we'd got to) and applies whatever came
  // back, then books the next round.
  async function sync() {
    if (state.syncing || !state.cursor) { scheduleSync(); return; }
    state.syncing = true;
    const v = state.view;
    try {
      const data = await api('sync.php', { since: state.cursor, topic: v ? v.topic.id : 0 });
      if (data.version && APP.version && data.version !== APP.version) offerUpdate();
      if (data.reload) {
        state.cursor = data.cursor;
        await loadTopics();
        if (v && state.view === v) {
          const page = await api('messages.php', { topic: v.topic.id, mode: 'bottom' });
          await openSealed(page.topic, page.messages);
          applyPage(page, 'replace');
          v.firstUnread = 0;
          renderMessages({ stay: true });
        }
      } else if (data.cursor !== state.cursor) {
        state.cursor = data.cursor;
        if (data.dms) {
          state.dms = data.dms;
          if (data.dms.some((d) => !state.users[d.partner])) loadDms();   // someone new wrote to you
          await openDmPreviews();
        }
        // A conversation key was shared or restored for the open DM: open it afresh.
        if (v && state.view === v && data.keys_changed && v.topic.kind === 'dm') { e2e.cks.delete(v.topic.id); openTopic(v.topic.id); return; }
        if (data.topics) { state.topics = data.topics; renderTopics(); refreshHeader(); }
        if (v && state.view === v && 'partner_read' in data && data.partner_read !== v.partnerRead) {
          v.partnerRead = data.partner_read;   // ✓ becomes ✓✓
          renderMessages({ stay: true });
        }
        if (v && state.view === v) {
          await openSealed(v.topic, data.messages || []);
          await openPins(v.topic, data.pins);
          applyChanges(v, data);
        }
      }
    } catch (e) {
      if (e.name !== 'NoSignal') console.warn(e);   // no signal is a state, not a fault to log
    } finally {
      state.syncing = false;
      scheduleSync();
    }
  }

  // Merge changed/new/deleted messages into the open topic.
  function applyChanges(v, data) {
    Object.assign(state.users, data.users || {});
    let changed = false;
    const byId = new Map(v.messages.map((m, i) => [m.id, i]));
    const lastId = v.messages.length ? v.messages[v.messages.length - 1].id : 0;
    for (const m of data.messages || []) {
      if (byId.has(m.id)) { v.messages[byId.get(m.id)] = m; changed = true; }
      else if (!v.hasNewer && m.id > lastId) { v.messages.push(m); changed = true; }
    }
    if (data.deleted?.length) {
      const gone = new Set(data.deleted);
      const before = v.messages.length;
      v.messages = v.messages.filter((m) => !gone.has(m.id));
      changed = changed || v.messages.length !== before;
    }
    if (data.pins) { v.pins = data.pins; v.pinIdx = 0; changed = true; }
    for (const m of data.messages || []) {
      if (m.rx_new && !v.reactions.includes(m.id)) v.reactions.push(m.id);
      if (!m.rx_new && m.user_id === APP.me) v.reactions = v.reactions.filter((id) => id !== m.id);
    }
    v.reactions.sort((a, b) => a - b);
    v.messages.sort((a, b) => a.id - b.id);
    if (changed) renderMessages({ stay: true });
  }

  // Put a message returned by an action straight into the view.
  function upsert(data) {
    const v = state.view;
    if (!v || !data?.message) return;
    Object.assign(state.users, data.users || {});
    const i = v.messages.findIndex((m) => m.id === data.message.id);
    if (i >= 0) v.messages[i] = data.message;
    else if (!v.hasNewer) v.messages.push(data.message);
    v.messages.sort((a, b) => a.id - b.id);
  }
