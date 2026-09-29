  // Typing @ for a member or ~ for a topic: the picker, keyboard choosing, and turning the chosen
  // name into a link when the message is sent. Plain @ and ~ in ordinary writing are left alone.
  // Also draws the @ and reaction buttons in the message box.

  // Watches what you type for @name or ~topic and offers matches. A plain @ or ~ that isn't
  // starting a name simply closes the picker.
  async function mentionSearch() {
    const ta = box(), list = $('.mention-list', chatEl);
    const before = ta.value.slice(0, ta.selectionStart);
    // "~" then a letter offers topics to link to. A bare "~", "~5", "~~" or "a~b" never
    // does, and nothing shows unless a topic matches, so ordinary tildes aren't disturbed.
    const tm = before.match(/(?:^|[^\p{L}\p{N}_~])~(\p{L}[^~\n]{0,39})$/u);
    if (tm && !tm[1].includes('  ')) {
      const q = tm[1].toLowerCase().trim();
      const found = state.topics.filter((t) => !q || t.title.toLowerCase().startsWith(q)
        || t.title.toLowerCase().split(/\s+/).some((w) => w.startsWith(q))).slice(0, 6);
      if (!found.length) { list.hidden = true; return; }
      list.innerHTML = found.map((t, i) => `<button class="${i ? '' : 'on'}" data-topic-pick="${t.id}" role="option">${topicIcon(t, 'mini')}<span>${esc(t.title)}</span></button>`).join('');
      list.hidden = false;
      list.dataset.start = before.length - tm[1].length - 1;
      return;
    }
    const m = before.match(/(?:^|[^\p{L}\p{N}_@])@([\p{L}\p{N}_ ]{0,30})$/u);
    if (!m || m[1].includes('  ')) { list.hidden = true; return; }
    if (!state.members) {
      try { state.members = (await api('members.php')).members; } catch (e) { return; }
    }
    const q = m[1].toLowerCase().trim();
    const found = state.members.filter((u) => u.id !== APP.me && (!q
      || u.name.toLowerCase().split(/\s+/).some((w) => w.startsWith(q)) || u.name.toLowerCase().startsWith(q)
      || (u.username || '').toLowerCase().startsWith(q))).slice(0, 6);
    if (!found.length) { list.hidden = true; return; }
    list.innerHTML = found.map((u, i) => `<button class="${i ? '' : 'on'}" data-mention="${u.id}" role="option">`
      + (u.photo ? `<img class="avatar xs2" src="${esc(u.photo)}" alt="">` : `<span class="avatar c${u.color % 7} xs2">${esc(initials(u.name))}</span>`) + `<span>${esc(u.name)}</span>`
      + (u.username ? `<span class="muted">@${esc(u.username)}</span>` : '') + '</button>').join('');
    list.hidden = false;
    list.dataset.start = before.length - m[1].length - 1;
  }

  // Puts the chosen topic's name into the message; it becomes a link when sent.
  function pickTopic(id) {
    const ta = box(), list = $('.mention-list', chatEl);
    const t = state.topics.find((x) => x.id === id);
    if (!t) return;
    const title = t.title.replace(/[[\]]/g, '');
    const start = +list.dataset.start;
    const insert = '~' + title + ' ';
    (state.topicPicks ||= []).push({ id: t.id, title });
    ta.value = ta.value.slice(0, start) + insert + ta.value.slice(ta.selectionStart);
    const pos = start + insert.length;
    ta.setSelectionRange(pos, pos);
    list.hidden = true;
    ta.focus();
    autosize();
    updateSendButton();
  }

  // On sending, each picked "~Topic" becomes a link to that topic.
  function withTopicLinks(text) {
    const picks = [...(state.topicPicks || [])].sort((a, b) => b.title.length - a.title.length);
    for (const p of picks) {
      text = text.split('~' + p.title).join(`[${p.title}](${location.origin}${APP.base}t/${p.id})`);
    }
    return text;
  }

  // Puts the chosen member's name in, remembering who they are for the link.
  function pickMention(id) {
    const ta = box(), list = $('.mention-list', chatEl);
    const u = state.members.find((x) => x.id === id);
    const start = +list.dataset.start;
    const insert = u.username ? '@' + u.username + ' ' : '@' + u.name + ' ';
    if (!u.username) state.mentionPicks.push({ user_id: u.id, name: u.name });
    ta.value = ta.value.slice(0, start) + insert + ta.value.slice(ta.selectionStart);
    const pos = start + insert.length;
    ta.setSelectionRange(pos, pos);
    list.hidden = true;
    ta.focus();
    autosize();
    updateSendButton();
  }

  // Arrow keys, Enter and Escape while the picker is open.
  function mentionKey(e) {
    const list = $('.mention-list', chatEl);
    const items = [...list.querySelectorAll('button')];
    let i = items.findIndex((b) => b.classList.contains('on'));
    if (e.key === 'Escape') { list.hidden = true; e.preventDefault(); return; }
    if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault();
      const it = items[Math.max(0, i)];
      if (it.dataset.topicPick) pickTopic(+it.dataset.topicPick); else pickMention(+it.dataset.mention);
      return;
    }
    e.preventDefault();
    items[i]?.classList.remove('on');
    i = (i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
    items[i].classList.add('on');
  }

  // The floating button that jumps to the oldest message that mentions you.
  function renderMentionButton() {
    const v = state.view, btn = $('.to-mention', chatEl);
    if (!v || !btn) return;
    v.mentions = v.mentions.filter((id) => id > v.lastRead);
    btn.hidden = !v.mentions.length;
    $('.count', btn).textContent = v.mentions.length > 1 ? v.mentions.length : '';
    renderReactionButton();
  }

  // The floating ❤ button that jumps to the oldest reaction you haven't seen.
  function renderReactionButton() {
    const v = state.view, btn = $('.to-reaction', chatEl);
    if (!v || !btn) return;
    btn.hidden = !v.reactions.length;
    btn.classList.toggle('stacked', !$('.to-mention', chatEl).hidden);
    $('.count', btn).textContent = v.reactions.length > 1 ? v.reactions.length : '';
  }
