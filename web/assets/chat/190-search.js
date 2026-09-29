  // Search: the whole group or one topic, with highlighted snippets and tap-to-jump. Inside a
  // conversation it searches on the device, because the server can't read sealed messages.

  // The search panel. In a conversation it searches the decrypted messages on this device,
  // because the server only holds sealed text.
  function openSearch(topic = null, query = '') {
    const el = document.createElement('div');
    el.className = 'search-panel';
    el.innerHTML = `<header class="bar"><button class="bar-back search-close" aria-label="Close search">‹</button>
        <input type="search" class="search-input" placeholder="${topic ? 'Search in ' + esc(topic.title) : 'Search all topics'}" aria-label="Search" enterkeyhint="search">
      </header>
      <div class="search-scope">${topic ? `In <strong>${esc(topic.title)}</strong> · <button class="link search-all">search all topics</button>` : 'All topics'}</div>
      <div class="search-results"><p class="loading">Type a word or two to search every message.</p></div>`;
    document.body.appendChild(el);
    const input = $('.search-input', el), out = $('.search-results', el);
    let scope = topic, timer = null, last = { q: '', before: 0 };
    const mark = (text, terms) => {
      let h = esc(text);
      for (const t of terms) h = h.replace(new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'giu'), '<mark>$1</mark>');
      return h;
    };
    async function run(more = false) {
      const q = input.value.trim();
      if (q.length < 2) { out.innerHTML = '<p class="loading">Type a word or two to search every message.</p>'; return; }
      if (!more) last = { q, before: 0 };
      const data = scope?.kind === 'dm' ? await searchDm(scope, q) : await api('search.php', { q, topic: scope ? scope.id : 0, before: last.before });
      if (input.value.trim() !== q) return;
      const html = data.results.map((r) => `<button class="result" data-topic="${r.topic}" data-msg="${r.id}">`
        + `<span class="result-head"><span class="n${r.color % 7}">${esc(r.name)}</span>${scope ? '' : ` <span class="muted">in ${esc(r.title)}</span>`}<span class="muted result-date">${listTime(r.at)}</span></span>`
        + `<span class="result-text">${mark(r.snippet, data.terms || [])}</span></button>`).join('');
      if (!more) out.innerHTML = html || '<p class="loading">No messages found.</p>';
      else { out.querySelector('.search-more')?.remove(); out.insertAdjacentHTML('beforeend', html); }
      if (data.more) {
        last.before = data.results[data.results.length - 1].id;
        out.insertAdjacentHTML('beforeend', '<button class="link search-more">Show more</button>');
      }
    }
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => run().catch((e) => toast(e.message)), 300); });
    el.addEventListener('click', (e) => {
      if (e.target.closest('.search-close')) { el.remove(); return; }
      if (e.target.closest('.search-more')) { run(true).catch((err) => toast(err.message)); return; }
      if (e.target.closest('.search-all')) {
        scope = null;
        $('.search-scope', el).textContent = 'All topics';
        input.placeholder = 'Search all topics';
        run().catch((err) => toast(err.message));
        return;
      }
      const r = e.target.closest('.result');
      if (r) { el.remove(); openTopic(+r.dataset.topic, +r.dataset.msg, true); }
    });
    if (query) { input.value = query; run().catch((e) => toast(e.message)); }
    else input.focus();
  }

  // Tap a #hashtag to see every message with it.
  document.addEventListener('click', (e) => {
    const tag = e.target.closest('.hashtag[data-tag]');
    if (tag) openSearch(null, tag.dataset.tag);
  });
  document.addEventListener('keydown', (e) => {
    const tag = e.target.closest?.('.hashtag[data-tag]');
    if (tag && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openSearch(null, tag.dataset.tag); }
  });

  document.querySelector('.search-open')?.addEventListener('click', () => openSearch());
