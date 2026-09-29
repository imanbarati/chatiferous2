  // Drawing messages: bubbles, replies and quotes, attachments, polls, reactions, service messages,
  // date chips, and link preview cards.
  //
  // Keeping the view steady matters as much as the HTML here: pictures, videos and preview cards
  // arrive late and would otherwise shove the conversation about, so their sizes are reserved in
  // advance and the scroll position is pinned while they load.

  // The first link in a text message gets a card (site, title, description, image), as in
  // Telegram. The server fetches and keeps it; here it's loaded once per link, two at a time.

  // The one link a message gets a preview card for: the first plain link in a message that has no
  // attachment or poll. Never in a conversation, where a preview would leak the link to the server.
  function previewUrl(m) {
    if (!m.text || m.poll || m.att?.length || m._p || state.view?.topic.kind === 'dm') return null;
    for (const e of [...(m.ents || [])].sort((a, b) => a.offset - b.offset)) {
      if (e.type !== 'url' && e.type !== 'text_link') continue;
      const href = safeHref(e.type === 'url' ? m.text.slice(e.offset, e.offset + e.length) : (e.url || ''));
      if (href && /^https?:/i.test(href) && !href.startsWith(location.origin + APP.base)) return href;
    }
    return null;
  }

  // The place a preview card goes: the card if we have it, or an empty space to fill in later.
  function previewSlot(m) {
    const u = previewUrl(m);
    if (!u) return '';
    const p = state.previews.get(u);
    if (p) return previewHtml(p);
    return p === null ? '' : `<span class="lp-slot" data-url="${esc(u)}"></span>`;
  }

  // A preview card: site, title, description, and the picture (wide across the top, or a thumbnail).
  function previewHtml(p) {
    const wide = p.image && p.w >= 300 && p.w / p.h >= 1.3;
    return `<a class="lp ${p.image ? (wide ? 'wide' : 'thumb') : ''}" href="${esc(p.url)}" target="_blank" rel="noopener noreferrer">`
      + `<span class="lp-text">${p.site ? `<span class="lp-site">${esc(p.site)}</span>` : ''}`
      + `${p.title ? `<span class="lp-title">${esc(p.title)}</span>` : ''}`
      + `${p.description ? `<span class="lp-desc">${esc(p.description)}</span>` : ''}</span>`
      + (p.image ? `<img class="lp-img" src="${esc(p.image)}" width="${p.w}" height="${p.h}" alt="" loading="lazy">` : '')
      + `</a>`;
  }

  const previewQueue = [];
  const previewLoading = new Set();
  // Asks the server for the previews of the links now on screen, newest first, a few at a time.
  function loadPreviews() {
    // Newest first: they're the ones on screen when a topic opens.
    for (const el of [...chatEl.querySelectorAll('.lp-slot')].reverse()) {
      const u = el.dataset.url;
      if (!state.previews.has(u) && !previewLoading.has(u) && !previewQueue.includes(u)) previewQueue.push(u);
    }
    while (previewLoading.size < 2 && previewQueue.length) {
      const u = previewQueue.shift();
      previewLoading.add(u);
      api('preview.php', { url: u }).then((d) => d.preview || null, () => null).then((p) => {
        previewLoading.delete(u);
        state.previews.set(u, p);
        fillPreview(u, p);
        loadPreviews();
      });
    }
  }

  // Put a loaded card in place without moving what you're reading.
  function fillPreview(u, p) {
    const scroller = $('.scroller', chatEl);
    if (!scroller) return;
    const bottom = atBottom(scroller);
    const top = scroller.getBoundingClientRect().top;
    for (const el of [...chatEl.querySelectorAll('.lp-slot')]) {
      if (el.dataset.url !== u) continue;
      const above = el.getBoundingClientRect().top < top;
      const before = scroller.scrollHeight;
      if (p) el.outerHTML = previewHtml(p); else el.remove();
      if (above && !bottom) scroller.scrollTop += scroller.scrollHeight - before;
    }
    if (bottom) scroller.scrollTop = scroller.scrollHeight;
  }

  // The small gray lines in the middle: topic created or renamed, message pinned, keys changed.
  function serviceHtml(m) {
    const u = state.users[m.user_id] || { name: 'Someone' };
    const s = m.service || {};
    const who = `<strong>${esc(u.name)}</strong>`;
    let text = '';
    if (s.action === 'topic_created') text = `${who} created the topic “${esc(s.title)}”`;
    else if (s.action === 'topic_renamed') text = `${who} renamed the topic to “${esc(s.title)}”`;
    else if (s.action === 'topic_closed') text = `${who} closed the topic`;
    else if (s.action === 'topic_reopened') text = `${who} reopened the topic`;
    else if (s.action === 'pinned') {
      text = `${who} pinned “${esc((s.preview || 'a message').slice(0, 60))}${(s.preview || '').length > 60 ? '…' : ''}”`;
      if (s.message_id) return `<div class="service" data-id="${m.id}"><button class="chip" data-jump="${s.message_id}">${text}</button></div>`;
    }
    return `<div class="service" data-id="${m.id}"><span class="chip">${text}</span></div>`;
  }

  // The quoted strip above a reply: who, and either the quoted words or the start of the message.
  function replyHtml(r) {
    if (r.deleted) return `<div class="reply deleted"><span class="reply-text">Deleted message</span></div>`;
    const u = state.users[r.user_id] || { name: '', color: 0 };
    return `<button class="reply c${u.color % 7} ${r.quote ? 'quoted' : ''}" data-jump="${r.id}"><span class="reply-name n${u.color % 7}">${esc(u.name)}</span>`
      + (r.quote ? `<span class="reply-text reply-quote">${esc(r.quote)}</span>` : `<span class="reply-text">${esc(r.text || ' ')}</span>`) + `</button>`;
  }

  const mediaDims = new Map();   // video address -> [width, height], once it has loaded
  chatEl.addEventListener('loadedmetadata', (e) => {
    const v = e.target;
    if (v.tagName === 'VIDEO' && v.videoWidth) mediaDims.set(v.getAttribute('src'), [v.videoWidth, v.videoHeight]);
  }, true);

  // One attachment: photo, video, GIF, voice note or file. In a conversation it's a placeholder
  // of the right size until the file is decrypted (loadSealedFiles).
  function attachmentHtml(a) {
    const src = `${APP.base}file.php?id=${a.id}`;
    // A sealed DM file: a placeholder the same shape, opened on this device (loadSealedFiles).
    if (a.sealed) {
      const media = a.kind === 'photo' || a.kind === 'video' || a.kind === 'animation';
      if (media) {
        const url = e2e.files.get(a.id), w = a.w || 4, h = a.h || 3;
        if (url && a.kind === 'photo') return `<button class="att-photo" data-photo="${url}"><img src="${url}" width="${w}" height="${h}" alt="Photo"></button>`;
        return `<img class="att-wait" src="${WAIT_IMG}" data-sealed="${a.id}" data-kind="${esc(a.kind)}" data-mime="${esc(a.mime || '')}" width="${w}" height="${h}" alt="">`;
      }
      return `<button class="att-file" data-sealed-file="${a.id}" data-name="${esc(a.name || 'file')}" data-mime="${esc(a.mime || '')}"><span class="file-icon">📄</span>`
        + `<span><span class="file-name">${esc(a.name || 'File')}</span><span class="file-size">${fileSize(a.size || 0)}</span></span></button>`;
    }
    const isImage = a.kind === 'photo' || (a.kind === 'animation' && /^image\//.test(a.mime || ''));
    if (isImage && a.available) {
      const w = a.w || 800, h = a.h || 600;
      return `<button class="att-photo" data-photo="${src}"><img src="${src}" width="${w}" height="${h}" loading="lazy" alt="Photo"></button>`;
    }
    // Videos play in the message; GIF-style clips play silently on a loop, as in Telegram.
    if (a.available && (a.kind === 'video' || a.kind === 'animation') && /^video\//.test(a.mime || '')) {
      const known = a.w && a.h ? [a.w, a.h] : mediaDims.get(src);
      const dims = known ? ` width="${known[0]}" height="${known[1]}"` : '';
      return a.kind === 'animation'
        ? `<video class="att-video gif" src="${src}"${dims} muted autoplay loop playsinline preload="metadata"></video>`
        : `<video class="att-video" src="${src}"${dims} controls playsinline preload="metadata"></video>`;
    }
    const icon = { video: '📹', voice: '🎤', animation: '🎞', photo: '🖼' }[a.kind] || '📄';
    const label = a.name || { video: 'Video', voice: 'Voice message', animation: 'GIF', photo: 'Photo' }[a.kind] || 'File';
    if (!a.available) {
      return `<div class="att-file unavailable"><span class="file-icon">${icon}</span><span><span class="file-name">${esc(label)}</span><span class="file-size">Not available</span></span></div>`;
    }
    return `<a class="att-file" href="${src}" download><span class="file-icon">${icon}</span>`
      + `<span><span class="file-name">${esc(label)}</span><span class="file-size">${fileSize(a.size)}</span></span></a>`;
  }

  // The daily "Finished?" poll's emoji options get their meanings shown beside them.
  const READING_LABELS = { '☑': 'Not started', '📖': 'Started, not finished', '✅': 'Finished' };

  // A poll: question, answers with bars and voter faces, your own answer marked, and the buttons
  // for voting, changing your vote, seeing who voted and closing it.
  function pollHtml(p) {
    const norm = (s) => s.replace(/\uFE0F/g, '').trim();
    const isReading = p.options.length === 3 && p.options.every((o) => READING_LABELS[norm(o.text)]);
    const kind = p.closed ? 'Final results' : (p.anonymous ? 'Anonymous poll' : 'Public poll') + (p.multiple ? ' · several answers' : '');
    const showResults = p.closed || p.voted;
    const rows = p.options.map((o) => {
      const pct = p.total ? Math.round((o.votes / p.total) * 100) : 0;
      const label = esc(o.text) + (isReading ? ` <span class="opt-note">${READING_LABELS[norm(o.text)]}</span>` : '');
      if (!showResults) {
        return `<button class="opt votable" data-option="${o.id}"><span class="${p.multiple ? 'check' : 'radio'}"></span>`
          + `<span class="opt-body"><span class="opt-text">${label}</span></span></button>`;
      }
      const faces = !p.anonymous && o.voters?.length
        ? `<span class="opt-voters" aria-hidden="true">${o.voters.slice(0, 3).map((id) => avatar(id, 'xs')).join('')}</span>` : '';
      // The mark sits in its own column, present on every row so the percentages stay in line;
      // only your own is filled in. The word is for screen readers, which can't see the dot.
      return `<div class="opt ${o.mine ? 'mine' : ''}"><span class="opt-mark" aria-hidden="true"></span>`
        + `<span class="pct">${pct}%</span>`
        + `<span class="opt-body"><span class="opt-text">${label}${o.mine ? '<span class="sr-only"> (your vote)</span>' : ''}</span>`
        + `<span class="bar-track"><span class="bar-fill" data-pct="${pct}"></span></span></span>${faces}</div>`;
    }).join('');
    const votes = p.total === 1 ? '1 vote' : `${p.total} votes`;
    const multiVote = !showResults && p.multiple ? '<button class="poll-submit" disabled>Vote</button>' : '';
    // While the poll is open you can take your vote back and choose again (Telegram: "Retract vote").
    const change = (showResults && !p.anonymous && p.total ? ' · <button type="button" class="poll-link poll-who">View votes</button>' : '')
      + (p.voted && !p.closed ? ' · <button type="button" class="poll-link poll-change">Change vote</button>' : '');
    return `<div class="poll"><div class="poll-q">${esc(p.question).replace(/\n/g, '<br>')}</div>`
      + `<div class="poll-kind">${kind}</div>${rows}${multiVote}<div class="poll-total">${p.total ? votes : 'No votes yet'}${change}</div></div>`;
  }

  // Public polls: who chose each answer, as Telegram's "View votes". Votes brought over from
  // Telegram have no names (the export only counted them), so they're shown as a number.
  function openVotes(m) {
    const p = m?.poll;
    if (!p || p.anonymous) return;
    const norm = (s) => s.replace(/\uFE0F/g, '').trim();
    const isReading = p.options.length === 3 && p.options.every((o) => READING_LABELS[norm(o.text)]);
    const el = document.createElement('div');
    el.className = 'modal gif-modal';
    el.innerHTML = `<div class="sheet-card votes-sheet" role="dialog" aria-label="Votes">
        <div class="fwd-top"><strong>Votes</strong><button class="link votes-close" aria-label="Close">✕</button></div>
        <div class="votes-list">${p.options.map((o) => `<section>
          <h3>${esc(o.text)}${isReading ? ` <span class="opt-note">${READING_LABELS[norm(o.text)]}</span>` : ''}<span class="votes-n">${o.votes}</span></h3>
          ${(o.voters || []).map((id) => `<div class="voter">${avatar(id, 'sm')}<span>${esc((state.users[id] || { name: '?' }).name)}</span></div>`).join('')}
          ${o.imported ? `<div class="voter muted small">+ ${o.imported} earlier ${o.imported === 1 ? 'vote' : 'votes'} from Telegram (names not recorded)</div>` : ''}
          ${!o.votes ? '<div class="voter muted small">No votes</div>' : ''}
        </section>`).join('')}</div>
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', (e) => { if (e.target === el || e.target.closest('.votes-close')) el.remove(); });
  }

  // The reaction chips under a message: emoji with either the faces of who reacted or a count.
  function reactionsHtml(list) {
    return `<div class="reactions">` + list.map((r) => {
      const who = r.users && r.users.length ? `<span class="rx-avatars">${r.users.slice(0, 3).map((u) => avatar(u, 'xs')).join('')}</span>` : `<span class="rx-count">${r.count}</span>`;
      return `<button class="rx ${r.mine ? 'mine' : ''}" data-react="${esc(r.emoji)}" aria-label="${esc(r.emoji)} ${r.count}${r.mine ? ', including you' : ''}"><span class="rx-emoji">${esc(r.emoji)}</span>${who}</button>`;
    }).join('') + `</div>`;
  }

  // Redraws the conversation. The hard part isn't the HTML: it's landing the reader where they
  // expect afterwards, whether they were at the bottom, holding a spot, or jumping to a message.
  function renderMessages(opts = {}) {
    const v = state.view;
    const list = $('.messages', chatEl);
    const scrollerNow = $('.scroller', chatEl);
    const wasAtBottom = scrollerNow && atBottom(scrollerNow);
    const oldTop = scrollerNow ? scrollerNow.scrollTop : 0;
    const ms = v.messages;
    let html = v.hasOlder ? '<div class="load-more">Loading older messages…</div>' : '';
    if (!v.hasOlder && ms.length === 0) html += '<div class="service"><span class="chip">Topic started! Send a message to start the topic.</span></div>';
    for (let i = 0; i < ms.length; i++) {
      const m = ms[i], prev = ms[i - 1], next = ms[i + 1];
      const d = new Date(m.at);
      if (!prev || !sameDay(new Date(prev.at), d)) html += `<div class="date-chip" data-date="${m.at}"><span>${chipDate(d)}</span></div>`;
      if (v.firstUnread && m.id === v.firstUnread) html += '<div class="unread-bar" id="unread-bar">Unread Messages</div>';
      html += renderMessage(plainView(m), prev, next, v);
    }
    if (v.hasNewer) html += '<div class="load-more newer">Loading newer messages…</div>';
    list.innerHTML = html;
    loadPreviews();
    loadSealedFiles();
    linkBibleRefs(list);
    liftReadingLink(list);
    for (const bar of list.querySelectorAll('.bar-fill')) bar.style.width = bar.dataset.pct + '%';
    renderPinbar();
    renderMentionButton();
    if (opts.stay) {
      scrollerNow.scrollTop = wasAtBottom && !v.hasNewer ? scrollerNow.scrollHeight : oldTop;
      state.stuck = atBottom(scrollerNow);
      updateBottomButton();
      queueRead();
      return;
    }
    if (opts.keep) return;
    const scroller = $('.scroller', chatEl);
    // Where to land: the message you asked for, else where you last were in this topic, else the
    // first unread message, else the bottom.
    const pos = opts.jump ? null : savedPos(v.topic.id);
    const back = pos ? list.querySelector(`[data-id="${pos.id}"]`) : null;
    const target = opts.jump ? list.querySelector(`[data-id="${opts.jump}"]`)
      : (back || (v.firstUnread ? $('#unread-bar', list) : null));
    if (target) {
      target.scrollIntoView({ block: opts.jump ? 'center' : 'start' });
      if (back && pos.off) scroller.scrollTop += pos.off;   // back to the exact line, not the message's top
      if (opts.jump) flash(target);
    } else {
      scroller.scrollTop = scroller.scrollHeight;
    }
    state.stuck = atBottom(scroller);   // a jump to a message or the unread marker isn't "at the bottom"
    updateBottomButton();
    queueRead();
  }

  // Briefly highlights a message you've jumped to.
  function flash(el) {
    el.classList.remove('flash');
    void el.offsetWidth;
    el.classList.add('flash');
  }

  // The pinned-message strip under the top bar; tapping it moves through the pins in turn.
  function renderPinbar() {
    const v = state.view, bar = $('.pinbar', chatEl), wrap = $('.pinbar-wrap', chatEl);
    if (!v.pins.length) { wrap.hidden = true; return; }
    const p = v.pins[v.pinIdx % v.pins.length];
    wrap.hidden = false;
    $('.pin-list', chatEl).hidden = v.pins.length < 2;
    $('.pinbar-label', bar).textContent = v.pins.length > 1 ? `Pinned message ${v.pins.length - (v.pinIdx % v.pins.length)} of ${v.pins.length}` : 'Pinned message';
    $('.pinbar-text', bar).textContent = p.deleted ? 'Deleted message' : p.text;
  }

  // All of a topic's pins in one list (as in Telegram): tap to go there; admins can unpin
  // each one, or all of them.
  function openPinList() {
    const v = state.view;
    const el = document.createElement('div');
    el.className = 'modal gif-modal';
    const draw = () => {
      const pins = [...v.pins].sort((x, y) => (x.at || '').localeCompare(y.at || '') || x.id - y.id);   // in chat order, as in Telegram
      el.innerHTML = `<div class="sheet-card votes-sheet pins-sheet" role="dialog" aria-label="Pinned messages">
          <div class="fwd-top"><strong>${pins.length} pinned ${pins.length === 1 ? 'message' : 'messages'}</strong><button class="link votes-close" aria-label="Close">✕</button></div>
          <div class="votes-list">${pins.map((p) => {
            const u = state.users[p.user_id] || { name: '', color: 0 };
            return `<div class="pin-item"><button class="pin-go" data-id="${p.id}" ${p.deleted ? 'disabled' : ''}>`
              + `<span class="pin-head"><span class="n${u.color % 7}">${esc(u.name)}</span>${p.at ? `<span class="muted">${listTime(p.at)}</span>` : ''}</span>`
              + `<span class="pin-text">${esc(p.deleted ? 'Deleted message' : p.text)}</span></button>`
              + (APP.admin ? `<button class="link pin-unpin" data-id="${p.id}">Unpin</button>` : '') + `</div>`;
          }).join('')}</div>
          ${APP.admin && pins.length > 1 ? `<button class="link danger pins-all">Unpin all ${pins.length}</button>` : ''}
        </div>`;
    };
    draw();
    document.body.appendChild(el);
    el.addEventListener('click', async (e) => {
      if (e.target === el || e.target.closest('.votes-close')) { el.remove(); return; }
      const go = e.target.closest('.pin-go');
      if (go) { el.remove(); jumpTo(+go.dataset.id); return; }
      try {
        const un = e.target.closest('.pin-unpin');
        if (un) {
          await api('pin.php', { id: un.dataset.id, pin: 0 }, true);
          v.pins = v.pins.filter((p) => p.id !== +un.dataset.id);
        } else if (e.target.closest('.pins-all')) {
          if (!confirm(`Unpin all ${v.pins.length} messages in this topic?`)) return;
          await api('pin.php', { topic: v.topic.id, all: 0 }, true);
          v.pins = [];
        } else return;
        v.pinIdx = 0;
        renderPinbar();
        if (!v.pins.length) el.remove(); else draw();
      } catch (err) { toast(err.message); }
    });
  }

  // Opens a topic or conversation: fetch a page of messages (around a given one when jumping),
  // draw the frame, and start the live updates.
  async function openTopic(id, jumpTo = 0, push = false) {
    const d = state.dms.find((x) => x.id === id);
    const t = state.topics.find((x) => x.id === id) || (d ? { id, title: '', kind: 'dm', partner: d.partner } : { id, title: '', color: 0 });
    if (t.kind === 'dm') state.listMode = 'dms';
    if (push) history.pushState({ topic: id }, '', `${APP.base}t/${id}${jumpTo ? '/' + jumpTo : ''}`);
    document.body.classList.add('chat-open');
    saveDraft();
    state.view = { topic: t, messages: [], hasOlder: false, hasNewer: false, firstUnread: 0, lastRead: 0, pins: [], pinIdx: 0, mentions: [], reactions: [], canPost: true };
    chatShell(t);
    renderTopics();
    bindChat();
    let data;
    try {
      data = await api('messages.php', jumpTo ? { topic: id, mode: 'around', id: jumpTo } : { topic: id });
      // Kept as it arrived, before the sealed direct messages are opened: what goes on the device is
      // what the server sent, not the plain text of anybody's private conversation. Only the plain
      // page is worth keeping — a jump to one message is a page around it, not where reading resumes.
      if (!jumpTo) localSave('t:' + id, data);
    } catch (e) {
      if (e.name !== 'NoSignal') throw e;
      data = await localLoad('t:' + id);
      if (state.view?.topic.id !== id) return;
      if (!data) {
        const list = $('.messages', chatEl);
        if (list) {
          list.innerHTML = '<div class="service"><span class="chip">No connection, '
            + 'and this topic hasn’t been read on this device yet.</span></div>';
        }
        initComposer();
        return;
      }
    }
    if (state.view?.topic.id !== id) return;
    await openSealed(data.topic, data.messages);   // a DM: opened on this device
    await openPins(data.topic, data.pins);
    if (state.view?.topic.id !== id) return;
    applyPage(data, 'replace');
    state.view.partnerRead = data.topic.partner_read || 0;
    if (data.topic.kind === 'dm') { state.listMode = 'dms'; renderTopics(); }
    chatShell(data.topic);
    bindChat();
    initComposer();
    renderMessages({ jump: jumpTo || 0 });
    if (APP.readingTopic && id === APP.readingTopic) warmReadings();
    // A link to one message (e.g. from a notification) has done its job: drop the message
    // from the address, so a later reload or app restart doesn't jump back to it.
    if (jumpTo && location.pathname !== `${APP.base}t/${id}`) history.replaceState(history.state, '', `${APP.base}t/${id}`);
    scheduleSync(0);
  }

  // Takes a page of messages from the server into the open view.
  function applyPage(data, how) {
    const v = state.view;
    Object.assign(state.users, data.users);
    v.topic = data.topic;
    v.pins = data.pins;
    v.lastRead = data.last_read_id;
    if (data.mentions) v.mentions = data.mentions;
    if (data.reactions) v.reactions = data.reactions;
    if ('can_post' in data) v.canPost = data.can_post;
    if (data.cursor && !state.cursor) state.cursor = data.cursor;
    if (how === 'replace') {
      v.messages = data.messages;
      v.hasOlder = data.has_older;
      v.hasNewer = data.has_newer;
      v.firstUnread = data.first_unread;
    } else if (how === 'older') {
      v.messages = data.messages.concat(v.messages);
      v.hasOlder = data.has_older;
    } else {
      v.messages = v.messages.concat(data.messages);
      v.hasNewer = data.has_newer;
    }
  }

  // Fetches the next page when you scroll off either end, keeping your place as it grows.
  async function loadMore(dir) {
    const v = state.view;
    if (!v || state.loading || !v.messages.length || (dir === 'older' ? !v.hasOlder : !v.hasNewer)) return;
    state.loading = true;
    const scroller = $('.scroller', chatEl);
    try {
      const anchor = dir === 'older' ? v.messages[0].id : v.messages[v.messages.length - 1].id;
      const data = await api('messages.php', { topic: v.topic.id, mode: dir === 'older' ? 'before' : 'after', id: anchor });
      if (state.view !== v) return;
      await openSealed(v.topic, data.messages);
      const oldHeight = scroller.scrollHeight, oldTop = scroller.scrollTop;
      applyPage(data, dir);
      renderMessages({ keep: true });
      if (dir === 'older') scroller.scrollTop = oldTop + (scroller.scrollHeight - oldHeight);
      keepView();          // the copy should hold what has been read, not only the first page
    } finally {
      state.loading = false;
    }
  }

  // Goes to a particular message, fetching the page it's on if it isn't already loaded.
  async function jumpTo(id) {
    const v = state.view;
    const el = $(`.messages [data-id="${id}"]`, chatEl);
    if (el) {
      state.stuck = false;
      el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      flash(el);
      return;
    }
    const data = await api('messages.php', { topic: v.topic.id, mode: 'around', id });
    if (state.view !== v) return;
    await openSealed(data.topic, data.messages);
    applyPage(data, 'replace');
    v.firstUnread = 0;
    renderMessages({ jump: id });
  }
