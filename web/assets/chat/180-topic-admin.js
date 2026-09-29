  // Making and changing topics (admins, and creators of their own): name, colour, icon from the
  // symbol library, pin to the top, close, and delete.

  const canManage = (t) => APP.admin || (t.created_by && t.created_by === APP.me);
  const TOPIC_COLORS = ['#4bb7ff', '#ffdb5c', '#e57aff', '#97e334', '#ff7999', '#ff714c'];
  let iconNames = null;

  // The names of the symbols in topic-icons.svg, read from the file itself for the picker.
  async function loadIconNames() {
    if (!iconNames) {
      const svg = await (await fetch(APP.icons)).text();
      iconNames = [...svg.matchAll(/id="ti-([a-z0-9-]+)"/g)].map((m) => m[1]);
    }
    return iconNames;
  }

  // New topic, or the settings of one: name, colour, symbol, and (for existing ones) pin to the
  // top, close and delete.
  async function topicDialog(t = null) {
    const names = await loadIconNames();
    const dlg = document.createElement('div');
    dlg.className = 'modal';
    let icon = t ? (t.icon || '') : '', color = t ? t.color % 6 : Math.floor(Math.random() * 6);
    dlg.innerHTML = `<form class="sheet-card topic-form">
        <h2>${t ? 'Edit topic' : 'New topic'}</h2>
        <label>Name<input name="title" maxlength="128" required value="${esc(t ? t.title : '')}"></label>
        ${t && t.general ? '' : `<div class="field-label">Colour</div><div class="swatches">${TOPIC_COLORS.map((c, i) =>
          `<button type="button" class="swatch" data-color="${i}" aria-label="Colour ${i + 1}"><span></span></button>`).join('')}</div>
        <div class="field-label">Icon</div>
        <div class="icon-grid"><button type="button" class="icon-opt" data-icon="" title="First letter">Aa</button>${names.map((n) =>
          `<button type="button" class="icon-opt" data-icon="${n}" title="${n.replace(/-/g, ' ')}"><svg viewBox="0 0 512 512"><use href="${APP.icons}#ti-${n}"/></svg></button>`).join('')}</div>`}
        <div class="row-buttons"><button type="button" class="link cancel">Cancel</button><button class="primary pad">${t ? 'Save' : 'Create topic'}</button></div>
      </form>`;
    document.body.appendChild(dlg);
    const form = $('form', dlg);
    // Swatch colours are set from JS (inline style attributes are blocked by our CSP).
    dlg.querySelectorAll('.swatch span').forEach((sw, i) => { sw.style.background = TOPIC_COLORS[i]; });
    const mark = () => {
      dlg.querySelectorAll('.swatch').forEach((b) => b.classList.toggle('on', +b.dataset.color === color));
      dlg.querySelectorAll('.icon-opt').forEach((b) => b.classList.toggle('on', b.dataset.icon === icon));
      dlg.querySelectorAll('.icon-opt').forEach((b) => { b.style.background = TOPIC_COLORS[color]; });
    };
    mark();
    dlg.addEventListener('click', (e) => {
      if (e.target === dlg || e.target.closest('.cancel')) { dlg.remove(); return; }
      const sw = e.target.closest('.swatch'); if (sw) { color = +sw.dataset.color; mark(); }
      const io = e.target.closest('.icon-opt'); if (io) { icon = io.dataset.icon; mark(); }
    });
    form.title.focus();
    form.onsubmit = async (e) => {
      e.preventDefault();
      try {
        if (t) {
          await api('topic.php', { action: 'edit', id: t.id, title: form.title.value, icon, color }, true);
          dlg.remove();
          await loadTopics();
          refreshHeader();
        } else {
          const r = await api('topic.php', { action: 'create', title: form.title.value, icon, color }, true);
          dlg.remove();
          await loadTopics();
          openTopic(r.id, 0, true);
        }
      } catch (err) {
        toast(err.message);
      }
    };
  }

  // Keep the open topic's header and posting rights in step with the topic list.
  function refreshHeader() {
    const v = state.view;
    if (!v || v.topic.kind === 'dm') return;
    const t = state.topics.find((x) => x.id === v.topic.id);
    if (!t) { closeTopic(true); toast('This topic was deleted.'); return; }
    const bar = $('.chat-bar', chatEl);
    if (bar) {
      $('.bar-name', bar).textContent = t.title;
      const old = $('.ticon', bar);
      if (old) old.outerHTML = topicIcon(t, 'lg');
    }
    const canPost = !t.closed || APP.admin || t.created_by === APP.me;
    if (canPost !== v.canPost) {
      v.canPost = canPost;
      $('.composer', chatEl).hidden = !canPost;
      $('.closed-note', chatEl).hidden = canPost;
    }
  }

  // "New topic" button on the topic list for admins (and members, if ever allowed).
  if (APP.admin || APP.membersCreateTopics) {
    const fab = document.createElement('button');
    fab.className = 'new-topic';
    fab.setAttribute('aria-label', 'New topic');
    fab.innerHTML = '✎';
    fab.addEventListener('click', () => topicDialog());
    $('.pane-list').appendChild(fab);
  }

  // Pull down on the topic list (from the top) to refresh, as in a phone app: the list follows the
  // finger with some resistance, a thin ring draws itself in the gap, and letting go past the
  // mark turns it into a spinner and reloads. Inside a topic, pulling down already means "older
  // messages", so it's only here.
  if (TOUCH) {
    const ind = document.createElement('div');
    ind.className = 'ptr';
    ind.setAttribute('aria-hidden', 'true');
    ind.innerHTML = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" pathLength="100"/></svg>';
    $('.pane-list').appendChild(ind);
    const ring = $('circle', ind);
    const PULL = 72;                        // how far the list must travel to refresh
    let y0 = null, d = 0, armed = false;
    const draw = () => {
      const p = Math.min(1, d / PULL);
      topicsEl.style.transform = d ? `translateY(${d}px)` : '';
      ind.style.top = (topicsEl.offsetTop + d / 2 - 14) + 'px';
      ind.style.opacity = String(Math.min(1, p * 1.4));
      ring.style.strokeDasharray = `${p * 80} 100`;
      ind.style.transform = `rotate(${p * 220 - 90}deg)`;
      if (p >= 1 && !armed) { armed = true; ind.classList.add('armed'); navigator.vibrate?.(8); }
      if (p < 1 && armed) { armed = false; ind.classList.remove('armed'); }
    };
    const settle = (to) => {             // glide the list to a resting place
      topicsEl.classList.add('ptr-settle');
      ind.classList.add('ptr-settle');
      d = to;
      draw();
      setTimeout(() => { topicsEl.classList.remove('ptr-settle'); ind.classList.remove('ptr-settle'); }, 300);
    };
    topicsEl.addEventListener('touchstart', (e) => {
      y0 = topicsEl.scrollTop <= 0 && e.touches.length === 1 ? e.touches[0].clientY : null;
    }, { passive: true });
    topicsEl.addEventListener('touchmove', (e) => {
      if (y0 === null) return;
      const pull = e.touches[0].clientY - y0;
      if (pull <= 0 || topicsEl.scrollTop > 0) { if (d) { d = 0; draw(); } return; }
      d = PULL * 1.6 * (1 - Math.exp(-pull / (PULL * 2.2)));   // gets heavier the further you pull
      draw();
    }, { passive: true });
    const end = () => {
      if (y0 === null) return;
      y0 = null;
      if (armed) {
        settle(PULL * 0.75);
        ind.classList.add('spin');
        setTimeout(() => location.reload(), 150);
      } else if (d) {
        settle(0);
      }
    };
    topicsEl.addEventListener('touchend', end, { passive: true });
    topicsEl.addEventListener('touchcancel', end, { passive: true });
  }
