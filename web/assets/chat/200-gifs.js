  // The GIF picker (KLIPY, when a key is configured): search, trending, and sending the chosen one.
  // The file is fetched by the server and stored with the message, so nothing loads from elsewhere
  // when the conversation is read later.

  // The GIF picker: what's trending, or a search. The chosen one is fetched by the server and
  // attached to the message like any other file.
  function openGifPicker() {
    const v = state.view;
    const el = document.createElement('div');
    el.className = 'modal gif-modal';
    el.innerHTML = `<div class="sheet-card gif-sheet">
        <div class="gif-top"><input type="search" class="gif-q" placeholder="Search GIFs" aria-label="Search GIFs" enterkeyhint="search">
          <button class="link gif-close" aria-label="Close">✕</button></div>
        <div class="gif-grid"><p class="loading">Loading…</p></div>
        <div class="gif-credit small muted">Powered by KLIPY</div>
      </div>`;
    document.body.appendChild(el);
    const input = $('.gif-q', el), grid = $('.gif-grid', el);
    let q = '', page = 1, more = false, busy = false, timer = null;
    async function load(reset) {
      if (busy) return;
      busy = true;
      try {
        if (reset) { page = 1; grid.innerHTML = '<p class="loading">Loading…</p>'; }
        const d = await api('gifs.php', { q, page });
        if (reset) grid.innerHTML = '';
        grid.insertAdjacentHTML('beforeend', d.items.map((g) =>
          `<button class="gif-item" data-url="${esc(g.url)}" title="${esc(g.title)}"><img src="${esc(g.thumb)}" width="${g.w}" height="${g.h}" alt="${esc(g.title)}" loading="lazy"></button>`).join(''));
        if (reset && !d.items.length) grid.innerHTML = '<p class="loading">No GIFs found.</p>';
        more = d.more;
        page++;
      } catch (e) {
        grid.innerHTML = `<p class="loading">${esc(e.message)}</p>`;
      } finally {
        busy = false;
      }
    }
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { q = input.value.trim(); load(true); }, 400); });
    grid.addEventListener('scroll', () => { if (more && grid.scrollTop + grid.clientHeight > grid.scrollHeight - 300) load(false); });
    el.addEventListener('click', async (e) => {
      if (e.target === el || e.target.closest('.gif-close')) { el.remove(); return; }
      const item = e.target.closest('.gif-item');
      if (!item || item.disabled) return;
      item.disabled = true;
      item.classList.add('sending');
      try {
        const params = { topic: v.topic.id, url: item.dataset.url };
        if (state.compose?.mode === 'reply') params.reply_to = state.compose.message.id;
        audio();
        let data;
        if (v.topic.kind === 'dm') {   // the GIF is public; the message carrying it is sealed like any other
          const g = await api('gifs.php', { url: item.dataset.url, store_only: 1 }, true);
          const { payload, sealed } = await sealTyped(v, '', { a: [{ id: g.id, kind: 'animation', mime: g.mime, w: g.w, h: g.h, plain: true }] }, { t: '', e: [] });
          data = await api('send.php', { topic: v.topic.id, text: sealed, attachments: g.id, ...(params.reply_to ? { reply_to: params.reply_to } : {}) }, true);
          data.message._p = payload;
        } else {
          data = await api('gifs.php', params, true);
        }
        playToc();
        el.remove();
        if (state.compose?.mode === 'reply') { state.compose = null; $('.compose-bar', chatEl).hidden = true; }
        upsert(data);
        v.firstUnread = 0;
        renderMessages();
      } catch (err) {
        item.disabled = false;
        item.classList.remove('sending');
        toast(err.message);
      }
    });
    load(true);
    if (!TOUCH) input.focus();
  }

  Promise.all([loadTopics(), loadDms()]).then(() => {
    route(); scheduleSync(); e2eReady(false).catch(() => {});
    // Once the app has nothing else to do, keep what a member would want with no signal. Nobody
    // should have to sit on a particular screen for this: opening the app at all is enough, and
    // opening the reading topic asks again in case this was cut short.
    //
    // The readings and their commentary go first, being what somebody plans their morning around;
    // a page of every topic follows, so a topic never opened still reads on a plane.
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 3000));
    idle(() => {
      setTimeout(() => warmReadings(), 4000);
      setTimeout(() => warmTopics(), 12000);
    });
  });
