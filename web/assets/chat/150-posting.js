  // The rest of the doing-things-to-messages wiring: swipe a message sideways to reply (phones), the
  // + attach menu, the who-reacted list, and the taps that send, edit, forward and react. Sending
  // itself is submit() in 100-composer.js.

  chatEl.addEventListener('click', (e) => {
    const v = state.view;
    if (!v) return;
    if (e.target.closest('.composer .send')) { submit(); return; }
    if (e.target.closest('.composer .attach')) { openAttachMenu(e.target.closest('.attach')); return; }
    if (e.target.closest('.cb-cancel')) { cancelCompose(); return; }
    const rm = e.target.closest('[data-remove]');
    if (rm) { state.uploads = state.uploads.filter((u) => u.key !== rm.dataset.remove); renderUploads(); return; }
    const pick = e.target.closest('[data-mention]');
    if (pick) { pickMention(+pick.dataset.mention); return; }
    const tpick = e.target.closest('.mention-list [data-topic-pick]');
    if (tpick) { pickTopic(+tpick.dataset.topicPick); return; }
    const who = e.target.closest('[data-user]');
    if (who) { openProfile(+who.dataset.user); return; }
    if (e.target.closest('.dm-restore')) {
      convKey(v.topic).then((ck) => ck && shareKey(v.topic, ck)).then(() => { toast('Restored. They can read this conversation again.'); $('.dm-note', chatEl)?.remove(); })
        .catch((err) => toast(err.message));
      return;
    }
    const sf = e.target.closest('[data-sealed-file]');
    if (sf) { saveSealedFile(+sf.dataset.sealedFile, sf.dataset.name, sf.dataset.mime); return; }
    if (e.target.closest('.dm-unblock')) { setBlock(v.topic.partner, false).catch((err) => toast(err.message)); return; }
    const own = e.target.closest('.text a[href]');
    if (own) {
      const u = new URL(own.href, location.origin);
      const tm = u.origin === location.origin && u.pathname.startsWith(APP.base) && u.pathname.slice(APP.base.length).match(/^t\/(\d+)(?:\/(\d+))?\/?$/);
      if (tm) { e.preventDefault(); openTopic(+tm[1], tm[2] ? +tm[2] : 0, true); return; }
    }
    const fl = e.target.closest('.fwd-link');
    if (fl) {
      const tid = +fl.dataset.fwdTopic, mid = +fl.dataset.fwdId;
      if (tid === v.topic.id) jumpTo(mid); else openTopic(tid, mid, true);
      return;
    }
    if (e.target.closest('.to-reaction') && v.reactions.length) { jumpTo(v.reactions[0]); return; }
    if (e.target.closest('.to-mention') && v.mentions.length) { const id = v.mentions.shift(); jumpTo(id); renderMentionButton(); return; }
    const rx = e.target.closest('[data-react]');
    if (rx) { react(+rx.closest('[data-id]').dataset.id, rx.dataset.react); return; }
    const opt = e.target.closest('.opt.votable');
    if (opt) {
      const msgEl = opt.closest('[data-id]');
      const m = msgById(+msgEl.dataset.id);
      if (!m.poll.multiple) { vote(m.id, [+opt.dataset.option]); return; }
      opt.classList.toggle('picked');
      const submitBtn = $('.poll-submit', msgEl);
      submitBtn.disabled = !msgEl.querySelector('.opt.picked');
      return;
    }
    if (e.target.closest('.poll-who')) { openVotes(msgById(+e.target.closest('[data-id]').dataset.id)); return; }
    if (e.target.closest('.poll-change')) { vote(+e.target.closest('[data-id]').dataset.id, []); return; }
    const submitVote = e.target.closest('.poll-submit');
    if (submitVote) {
      const msgEl = submitVote.closest('[data-id]');
      vote(+msgEl.dataset.id, [...msgEl.querySelectorAll('.opt.picked')].map((o) => +o.dataset.option));
      return;
    }
    // On touch screens, tapping a message opens its menu (as in Telegram for Android).
    if (TOUCH) {
      const bubble = e.target.closest('.bubble, .service .chip:not(button)');
      if (bubble && !e.target.closest('a, button, video, [data-user], .att-photo, .hashtag, .spoiler:not(.revealed)')) {
        const m = msgById(+bubble.closest('[data-id]').dataset.id);
        if (m) openMenu(m);
      }
    }
  });

  chatEl.addEventListener('contextmenu', (e) => {
    // Right-click (or, on Android, long-press) a reaction: who reacted.
    const rxChip = e.target.closest('.rx[data-react]');
    if (rxChip) { e.preventDefault(); openReacters(+rxChip.closest('[data-id]').dataset.id, rxChip.dataset.react); return; }
    const el = e.target.closest('.messages [data-id]');
    if (!el || e.target.closest('a')) return;
    const m = msgById(+el.dataset.id);
    if (!m) return;
    e.preventDefault();
    openMenu(m, e.clientX, e.clientY);
  });

  // Desktop: double-click a message to reply (Telegram's default).
  chatEl.addEventListener('dblclick', (e) => {
    if (TOUCH) return;
    const el = e.target.closest('.messages .msg');
    if (!el || e.target.closest('a, button')) return;
    const m = msgById(+el.dataset.id);
    if (m) { window.getSelection()?.removeAllRanges(); startReply(plainView(m)); }
  });

  // Touch: hold a reaction for half a second to see who reacted (iPhones have no long-press event).
  let rxHold = null, rxHeld = false;
  chatEl.addEventListener('pointerdown', (e) => {
    const chip = e.target.closest('.rx[data-react]');
    if (!chip || e.pointerType !== 'touch') return;
    rxHeld = false;
    rxHold = setTimeout(() => {
      rxHeld = true;
      setTimeout(() => { rxHeld = false; }, 1500);
      openReacters(+chip.closest('[data-id]').dataset.id, chip.dataset.react);
    }, 500);
  });
  for (const t of ['pointerup', 'pointercancel', 'pointermove']) {
    chatEl.addEventListener(t, (e) => { if (t !== 'pointermove' || Math.abs(e.movementY) > 4) clearTimeout(rxHold); });
  }
  // After a hold, the finger lifting mustn't count as a tap: not on the reaction (which would
  // toggle it) nor on the sheet that just opened under the finger (which would close it).
  document.addEventListener('click', (e) => {
    if (rxHeld) { rxHeld = false; e.stopImmediatePropagation(); e.preventDefault(); }
  }, true);

  // The sheet listing who reacted with a given emoji (hold a reaction chip).
  async function openReacters(messageId, emoji) {
    if (document.querySelector('.reacters-sheet')) return;
    let data;
    try { data = await api('reactions.php', { id: messageId }); } catch (e) { toast(e.message); return; }
    Object.assign(state.users, data.users || {});
    const list = [...data.reactions].sort((a, b) => (b.emoji === emoji) - (a.emoji === emoji));
    const el = document.createElement('div');
    el.className = 'modal gif-modal';
    el.innerHTML = `<div class="sheet-card votes-sheet reacters-sheet" role="dialog" aria-label="Reactions">
        <div class="fwd-top"><strong>Reactions</strong><button class="link votes-close" aria-label="Close">✕</button></div>
        <div class="votes-list">${list.map((r) => `<section>
          <h3><span class="rx-emoji">${esc(r.emoji)}</span><span class="votes-n">${r.users.length + r.extra}</span></h3>
          ${r.users.map((id) => `<div class="voter">${avatar(id, 'sm')}<span>${esc((state.users[id] || { name: '?' }).name)}</span></div>`).join('')}
          ${r.extra ? `<div class="voter muted small">+ ${r.extra} from Telegram (names not recorded)</div>` : ''}
        </section>`).join('') || '<p class="loading">No reactions.</p>'}</div>
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', (e) => { if (e.target === el || e.target.closest('.votes-close')) el.remove(); });
  }

  // Touch: swipe a message left to reply.
  let swipe = null;
  chatEl.addEventListener('pointerdown', (e) => {
    if (e.pointerType !== 'touch') return;
    const el = e.target.closest('.messages .msg');
    if (el) swipe = { el, x: e.clientX, y: e.clientY, dx: 0, locked: false };
  });
  chatEl.addEventListener('pointermove', (e) => {
    if (!swipe) return;
    const dx = e.clientX - swipe.x, dy = e.clientY - swipe.y;
    if (!swipe.locked) {
      if (Math.abs(dy) > 12) { swipe = null; return; }
      if (dx < -12) swipe.locked = true; else return;
    }
    swipe.dx = Math.max(-90, Math.min(0, dx));
    swipe.el.style.transform = `translateX(${swipe.dx}px)`;
    swipe.el.classList.toggle('swipe-ready', swipe.dx < -60);
  });
  const endSwipe = () => {
    if (!swipe) return;
    const { el, dx } = swipe;
    el.style.transform = '';
    el.classList.remove('swipe-ready');
    if (dx < -60) { const m = msgById(+el.dataset.id); if (m) startReply(plainView(m)); }
    swipe = null;
  };
  chatEl.addEventListener('pointerup', endSwipe);
  chatEl.addEventListener('pointercancel', endSwipe);

  // The + menu: photo, file, GIF (when GIF search is set up) and poll (not in conversations).
  function openAttachMenu(anchor) {
    closeMenu();
    const el = document.createElement('div');
    el.className = 'ctx-backdrop';
    el.innerHTML = `<div class="ctx" role="menu"><div class="menu-actions">
      <button data-att="photo" role="menuitem"><span class="mi">🖼</span>Photo</button>
      <button data-att="file" role="menuitem"><span class="mi">📄</span>File</button>
      ${APP.gifs ? '<button data-att="gif" role="menuitem"><span class="mi">🎞</span>GIF</button>' : ''}
      ${state.view?.topic.kind === 'dm' ? '' : '<button data-att="poll" role="menuitem"><span class="mi">📊</span>Poll</button>'}</div></div>`;
    document.body.appendChild(el);
    if (!TOUCH) {
      const menu = $('.ctx', el), r = anchor.getBoundingClientRect();
      menu.classList.add('floating');
      menu.style.left = r.left + 'px';
      menu.style.top = (r.top - menu.getBoundingClientRect().height - 8) + 'px';
    }
    el.addEventListener('click', (e) => {
      const kind = e.target.closest('[data-att]')?.dataset.att;
      el.remove();
      // Asking for JPEG/PNG makes iPhones convert their HEIC photos automatically.
      if (kind === 'photo') $('.photo-input', chatEl).click();
      if (kind === 'file') $('.any-input', chatEl).click();
      if (kind === 'poll') openPollDialog();
      if (kind === 'gif') openGifPicker();
    });
  }

  chatEl.addEventListener('change', (e) => {
    if (!e.target.classList.contains('file-input')) return;
    [...e.target.files].forEach(addUpload);
    e.target.value = '';
  });

  // Desktop: drop files onto the topic to attach them.
  chatEl.addEventListener('dragover', (e) => { if (state.view?.canPost && e.dataTransfer?.types.includes('Files')) e.preventDefault(); });
  chatEl.addEventListener('drop', (e) => {
    if (!state.view?.canPost || !e.dataTransfer?.files.length) return;
    e.preventDefault();
    [...e.dataTransfer.files].forEach(addUpload);
  });

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
  window.addEventListener('beforeunload', saveDraft);
