  // Getting around: the URL routes (a topic, a message, the Messages list), opening and closing a
  // topic, the photo viewer, toasts, and the banner offering a refresh when a new version has been
  // deployed while the app was open.

  // If you're at the bottom and something above changes size (a picture, video or link card
  // loading, or the list being redrawn), stay at the bottom instead of drifting up.
  let bottomWatch = null;
  // Wires up a freshly drawn conversation: scrolling, swipes, and the buttons in its frame.
  function bindChat() {
    const scroller = $('.scroller', chatEl);
    if (!scroller) return;
    state.stuck = true;
    bottomWatch?.disconnect();
    bottomWatch = new ResizeObserver(() => {
      if (state.stuck && !state.view?.hasNewer) scroller.scrollTop = scroller.scrollHeight;
    });
    bottomWatch.observe($('.messages', chatEl));
    bottomWatch.observe(scroller);
    if (scroller.dataset.bound) return;     // a shell that was kept has kept its listeners too
    scroller.dataset.bound = '1';
    scroller.addEventListener('scroll', () => {
      state.stuck = atBottom(scroller);
      if (scroller.scrollTop < 400) loadMore('older');
      if (scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 400) loadMore('newer');
      queueRead();
      updateFloatDate();
      savePosSoon();
    }, { passive: true });
  }

  chatEl.addEventListener('click', async (e) => {
    const v = state.view;
    const jump = e.target.closest('[data-jump]');
    if (jump) { e.preventDefault(); jumpTo(+jump.dataset.jump); return; }
    const photo = e.target.closest('[data-photo]');
    if (photo) { showPhoto(photo.dataset.photo); return; }
    const spoiler = e.target.closest('.spoiler');
    if (spoiler) { spoiler.classList.add('revealed'); return; }
    if (e.target.closest('.pin-list') && v?.pins.length) { openPinList(); return; }
    if (e.target.closest('.pinbar') && v?.pins.length) {
      const p = v.pins[v.pinIdx % v.pins.length];
      v.pinIdx++;
      renderPinbar();
      if (!p.deleted) jumpTo(p.id);
      return;
    }
    if (e.target.closest('.to-bottom') && v) {
      if (v.hasNewer) {
        const data = await api('messages.php', { topic: v.topic.id, mode: 'bottom' });
        await openSealed(data.topic, data.messages);
        applyPage(data, 'replace');
        v.firstUnread = 0;
        renderMessages();
      } else {
        const s = $('.scroller', chatEl);
        s.scrollTo({ top: s.scrollHeight, behavior: 'smooth' });
      }
      return;
    }
    const back = e.target.closest('.bar-back');
    if (back) { e.preventDefault(); closeTopic(true); }
  });

  topicsEl.addEventListener('click', (e) => {
    if (e.target.closest('[data-folder="dms"]')) { e.preventDefault(); showList('dms', true); return; }
    if (e.target.closest('.dm-back')) { showList('topics', true); return; }
    const a = e.target.closest('a.topic');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    openTopic(+a.dataset.id, 0, true);
  });

  // Back to the list: save the draft, stop the topic's updates, and mark it read if you were at
  // the bottom.
  function closeTopic(push) {
    savePos();
    saveDraft();
    if (state.view?.topic.kind === 'dm') state.listMode = 'dms';
    if (push) history.pushState({}, '', APP.base + (state.listMode === 'dms' ? 'messages' : ''));
    document.body.classList.remove('chat-open');
    state.view = null;
    chatEl.innerHTML = '<div class="chat-empty"><span>'
      + (state.listMode === 'dms' ? 'Select a conversation' : 'Select a topic') + '</span></div>';
    renderTopics();
    loadTopics();
  }

  // Decides what to show from the address: a topic, a message within one, the Messages list, or
  // the topic list. Called at start-up and on Back.
  function route() {
    const path = location.pathname.slice(APP.base.length);
    const m = path.match(/^t\/(\d+)(?:\/(\d+))?/);
    if (m) { openTopic(+m[1], m[2] ? +m[2] : 0); return; }
    state.listMode = path.startsWith('messages') ? 'dms' : 'topics';
    const empty = $('.chat-empty span');
    if (empty) empty.textContent = state.listMode === 'dms' ? 'Select a conversation' : 'Select a topic';
    if (state.view) closeTopic(false); else renderTopics();
  }

  window.addEventListener('popstate', route);

  // Photo viewer
  const viewer = $('#viewer');
  // The full-screen photo viewer.
  function showPhoto(src) {
    $('img', viewer).src = src;
    viewer.hidden = false;
  }
  viewer.addEventListener('click', () => { viewer.hidden = true; $('img', viewer).src = ''; });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !viewer.hidden) viewer.click();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { saveDraft(); keepView(true); }
    else { scheduleSync(0); queueRead(); }
  });
  window.addEventListener('pagehide', () => { savePos(); saveDraft(); keepView(true); });

  // The browser knows there is no signal before we try to use it, so come up already saying so
  // rather than after a failed call. A signal coming back is only a hint — the next call settles
  // it — so returning is handled by asking for the topic list again.
  if (!navigator.onLine) setOffline(true);
  window.addEventListener('offline', () => setOffline(true));
  window.addEventListener('online', () => { loadTopics(); scheduleSync(0); });

  // =====================================================================
  // Posting
  // =====================================================================

  const TOUCH = matchMedia('(pointer: coarse)').matches;
  const EDIT_WINDOW_MS = 48 * 3600 * 1000;
  const QUICK = ['👍', '❤', '😁', '🔥', '💯', '🙏', '👏', '🤔'];
  const ALL_REACTIONS = ['👍', '❤', '😁', '🔥', '💯', '🙏', '👏', '🤣', '🤔', '👌', '🥰', '🕊', '😢', '🆒', '⚡', '😱', '🤨',
    '💔', '🤯', '😭', '🤓', '❤‍🔥', '😎', '🎉', '👀', '🤩', '😇', '🫡', '👎'];
  const box = () => $('.composer textarea', chatEl);

  // A new version was deployed while this copy was open: offer a one-tap refresh
  // (an installed app has no reload button). Drafts are saved first.
  function offerUpdate() {
    if ($('#update-bar')) return;
    const bar = document.createElement('div');
    bar.id = 'update-bar';
    bar.className = 'toast update-bar';
    bar.setAttribute('role', 'status');
    bar.innerHTML = '<span>A new version is ready.</span><button type="button" class="update-now">Refresh</button>';
    bar.querySelector('.update-now').addEventListener('click', () => { saveDraft(); location.reload(); });
    document.body.appendChild(bar);
  }

  // A short message along the bottom (errors, confirmations). Replaces any showing already.
  function toast(msg) {
    let t = $('#toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'toast';
      t.className = 'toast';
      t.setAttribute('role', 'alert');
      document.body.appendChild(t);
      t.addEventListener('click', () => { t.hidden = true; });
    }
    t.innerHTML = `<span>${esc(msg)}</span><button aria-label="Dismiss">✕</button>`;
    t.hidden = false;
  }
