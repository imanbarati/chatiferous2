  // The topic list (the left pane, and the whole screen on a phone): rows with icon, last message,
  // unread counts and pins. Also the Topics/Messages switch, whose Messages half lives in
  // 040-dm-list.js.

  // Keep the app icon's number (set by notifications) equal to what's really unread,
  // not muted, so it clears once you've read things anywhere.
  function syncAppBadge() {
    if (!('setAppBadge' in navigator)) return;
    const n = [...state.topics, ...state.dms].reduce((sum, t) => sum + (t.muted ? 0 : t.unread), 0);
    (n ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {});
  }

  // Draws the whole list (or hands over to the Messages list), keeping the open topic marked.
  function renderTopics() {
    syncAppBadge();
    newDmButton();
    if (state.listMode === 'dms') { renderDmList(); return; }
    const current = state.view?.topic.id;
    topicsEl.innerHTML = messagesRow() + state.topics.map((t) => {
      const last = t.last;
      const draft = t.id === current ? '' : draftFor(t.id);
      // Who wrote it goes on its own line above the extract, as in Telegram's forum list, and
      // isn't bold: the topic's own name is the bold thing in the row.
      const sender = last && last.name && !last.preview.startsWith(last.name)
        ? `<span class="topic-row"><span class="topic-sender">${esc(last.mine ? 'You' : last.name)}</span></span>` : '';
      // A drawn heart rather than the ❤ character, which every platform renders as a red emoji:
      // it ignores the colour it is given and so came out red on a red badge.
      const badges = (t.reactions ? '<span class="badge rx-new" title="New reactions to your messages">'
        + '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.6l-1.6-1.45C4.9 14.2 1.5 11.1 1.5 7.3 1.5 4.4 3.8 2.1 6.7 2.1c1.6 0 3.2.76 4.2 2l1.1 1.3 1.1-1.3c1-1.24 2.6-2 4.2-2 2.9 0 5.2 2.3 5.2 5.2 0 3.8-3.4 6.9-8.9 11.85L12 20.6z"/></svg>'
        + '</span>' : '')
        + (t.mentions ? '<span class="badge at" title="Mentions">@</span>' : '')
        + (t.unread ? `<span class="badge ${t.muted ? 'muted' : ''}">${t.unread > 999 ? '999+' : t.unread}</span>` : '')
        + (!t.unread && !t.mentions && t.pinned ? '<span class="pin-icon" title="Pinned">📌</span>' : '');
      return `<a class="topic ${t.id === current ? 'active' : ''}" href="${APP.base}t/${t.id}" data-id="${t.id}">`
        + topicIcon(t)
        + `<span class="topic-main">`
        + `<span class="topic-row"><span class="topic-title">${t.closed ? '<span class="lock" title="Closed">🔒</span>' : ''}${esc(t.title)}${t.muted ? ' <span class="muted-icon" title="Muted">🔕</span>' : ''}</span>`
        + `<span class="topic-time">${last ? listTime(last.at) : ''}</span></span>`
        + (draft ? '' : sender)
        + `<span class="topic-row"><span class="topic-preview">${draft ? `<span class="draft-label">Draft: </span>${esc(draft.replace(/\s+/g, ' ').slice(0, 120))}` : esc(last ? last.preview : '')}</span>${badges}</span>`
        + `</span></a>`;
    }).join('');
  }
