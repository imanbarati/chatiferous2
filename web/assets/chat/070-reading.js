  // What you've read: marking a topic read as you reach the bottom, the unread divider, and the
  // jump-to-first-unread and jump-to-unread-reaction buttons.

  // Near enough to the bottom to count as "reading the latest" (a small margin, as Telegram has).
  // Where you had scrolled to in a topic, so coming back puts you there rather than at the bottom.
  // Kept on the device (a scroll position belongs to a screen, not to an account), as the id of
  // the message at the top of the view plus how far into it you were.
  function savePos() {
    const v = state.view, scroller = $('.scroller', chatEl);
    if (!v || !scroller) return;
    try {
      if (atBottom(scroller)) {                 // at the bottom: come back to the bottom
        localStorage.removeItem('pos:' + v.topic.id);
        return;
      }
      const top = scroller.getBoundingClientRect().top;
      for (const el of chatEl.querySelectorAll('.messages [data-id]')) {
        const box = el.getBoundingClientRect();
        if (box.bottom > top + 4) {
          localStorage.setItem('pos:' + v.topic.id, JSON.stringify({ id: +el.dataset.id, off: Math.round(top - box.top) }));
          return;
        }
      }
    } catch (e) { /* private window: never mind */ }
  }

  function savedPos(topicId) {
    try { return JSON.parse(localStorage.getItem('pos:' + topicId) || 'null'); } catch (e) { return null; }
  }

  let posTimer = null;
  // The place is kept in localStorage, which is immediate; the messages it points into live in the
  // copy on this device, which is not. Keeping them together means the place still finds something
  // to land on when the signal has gone.
  const savePosSoon = () => {
    clearTimeout(posTimer);
    posTimer = setTimeout(() => { savePos(); keepView(); }, 400);
  };

  function atBottom(scroller) {
    return scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 60;
  }

  // Everything above the bottom edge of the view counts as read.
  function queueRead() {
    const v = state.view;
    if (!v || document.hidden) return;
    const scroller = $('.scroller', chatEl);
    const bottom = scroller.getBoundingClientRect().bottom;
    const top = scroller.getBoundingClientRect().top;
    let maxSeen = 0;
    const seenRx = [];
    for (const el of chatEl.querySelectorAll('.messages [data-id]')) {
      const r = el.getBoundingClientRect();
      if (r.top < bottom - 20) maxSeen = Math.max(maxSeen, +el.dataset.id);
      if (r.top < bottom - 20 && r.bottom > top + 20 && v.reactions.includes(+el.dataset.id)) seenRx.push(+el.dataset.id);
    }
    if (seenRx.length) {
      v.reactions = v.reactions.filter((id) => !seenRx.includes(id));
      state.pendingRx = [...new Set([...(state.pendingRx || []), ...seenRx])];
      renderReactionButton();
    }
    if (maxSeen > v.lastRead || seenRx.length) {
      v.lastRead = Math.max(v.lastRead, maxSeen);
      state.pendingRead = v.lastRead;
      clearTimeout(state.readTimer);
      state.readTimer = setTimeout(() => {
        const rx = (state.pendingRx || []).join(',');
        state.pendingRx = [];
        api('read.php', { topic: v.topic.id, message: state.pendingRead, reactions: rx }, true).then(loadTopics).catch(() => {});
      }, 800);
    }
    updateBottomButton();
  }

  // The jump-to-bottom button and its unread count: hidden while you're already at the bottom.
  function updateBottomButton() {
    const v = state.view, btn = $('.to-bottom', chatEl), scroller = $('.scroller', chatEl);
    if (!v || !btn) return;
    btn.hidden = atBottom(scroller) && !v.hasNewer;
    const t = state.topics.find((x) => x.id === v.topic.id) || state.dms.find((x) => x.id === v.topic.id);
    const count = $('.count', btn);
    count.hidden = !(t && t.unread);
    if (t) count.textContent = t.unread;
  }

  // Floating date: the date of the topmost visible message, shown while scrolling.
  let floatTimer = null;
  // The date chip that floats at the top while you scroll, showing the day you're looking at.
  function updateFloatDate() {
    const scroller = $('.scroller', chatEl), fd = $('.float-date', chatEl);
    if (!scroller || !fd) return;
    const top = scroller.getBoundingClientRect().top + 8;
    let label = '';
    for (const el of chatEl.querySelectorAll('.messages .msg, .messages .service')) {
      const r = el.getBoundingClientRect();
      if (r.bottom > top) {
        const m = state.view.messages.find((x) => x.id === +el.dataset.id);
        if (m) label = chipDate(new Date(m.at));
        break;
      }
    }
    // Hide it when a real date chip is right at the top.
    const chipAtTop = [...chatEl.querySelectorAll('.messages .date-chip')].some((c) => Math.abs(c.getBoundingClientRect().top - top) < 30);
    $('span', fd).textContent = label;
    fd.classList.toggle('fade', !label || chipAtTop);
    clearTimeout(floatTimer);
    floatTimer = setTimeout(() => fd.classList.add('fade'), 1500);
  }
