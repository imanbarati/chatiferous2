// Sets light or dark before the page draws (loaded in <head>, so no white flash).
// Follows the device setting unless the member chose Light or Dark in the ⋮ menu.
(() => {
  const media = matchMedia('(prefers-color-scheme: dark)');
  const choice = () => { try { return localStorage.getItem('theme') || 'auto'; } catch (e) { return 'auto'; } };
  const apply = () => {
    const c = choice();
    const dark = c === 'dark' || (c === 'auto' && media.matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = dark ? '#212121' : '#ffffff';
    const label = document.querySelector('[data-theme-label]');
    if (label) label.textContent = { auto: 'Auto', light: 'Light', dark: 'Dark' }[c];
  };
  apply();
  // Text size (A− / A+ in the ⋮ menu). Everything is sized in rem, so changing the root
  // size scales the whole app — messages, names, the composer — like the device setting.
  const SIZES = [15, 17, 19, 21, 23];   // 17px is the default
  const sizeIndex = () => {
    let i = 1;
    try { i = parseInt(localStorage.getItem('textSize') ?? '1', 10); } catch (e) { /* storage unavailable */ }
    return Number.isInteger(i) && i >= 0 && i < SIZES.length ? i : 1;
  };
  const applySize = () => {
    const i = sizeIndex();
    document.documentElement.style.fontSize = i === 1 ? '' : SIZES[i] + 'px';
    for (const b of document.querySelectorAll('[data-text-size]')) {
      b.disabled = b.dataset.textSize === '-1' ? i === 0 : i === SIZES.length - 1;
    }
  };
  applySize();
  document.addEventListener('DOMContentLoaded', applySize);
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-text-size]');
    if (!b) return;
    const i = Math.max(0, Math.min(SIZES.length - 1, sizeIndex() + Number(b.dataset.textSize)));
    try { localStorage.setItem('textSize', String(i)); } catch (err) { /* storage unavailable */ }
    applySize();
  });
  media.addEventListener('change', apply);
  // ⋮ menu → Refresh: reload the page (installed apps have no reload button).
  // If a script error ever leaves a full-screen sheet or backdrop sitting invisibly over the page,
  // taps go nowhere and the only cure is a refresh. So: report the error (once a minute at most),
  // clear anything stuck, and say so, rather than leaving someone tapping at a dead page.
  let reported = false;
  const recover = (msg, where) => {
    for (const el of document.querySelectorAll('.modal, .ctx-backdrop, .b-picker, .b-sheet, .search-panel')) {
      el.remove();
    }
    if (reported) return;
    reported = true;
    const base = document.body?.dataset?.app || document.body?.dataset?.bible;
    let app = {};
    try { app = JSON.parse(base || '{}'); } catch (e) { /* not an app page */ }
    if (!app.base || !app.csrf) return;
    fetch(app.base + 'api/jserror.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': app.csrf },
      body: new URLSearchParams({ page: location.pathname, msg: String(msg).slice(0, 300), where: String(where || '').slice(0, 300) }),
    }).catch(() => {});
  };
  window.addEventListener('error', (e) => recover(e.message, (e.filename || '') + ':' + (e.lineno || '')));
  window.addEventListener('unhandledrejection', (e) => recover(e.reason?.message || e.reason, 'promise'));

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    for (const d of document.querySelectorAll('details.menu[open]')) d.open = false;
  });

  document.addEventListener('click', (e) => {
    // A tap anywhere outside an open ⋮ menu closes it. True of every page, not only the chat.
    for (const d of document.querySelectorAll('details.menu[open]')) {
      if (!d.contains(e.target)) d.open = false;
    }
    if (e.target.closest('[data-refresh]')) location.reload();
    // An installed app has no address bar, so this is how you get at the link to send someone.
    if (e.target.closest('[data-copy-link]')) {
      const url = location.href;
      const done = (msg) => {
        const b = e.target.closest('[data-copy-link]');
        const was = b.textContent;
        b.textContent = msg;
        setTimeout(() => { b.textContent = was; }, 1600);
      };
      if (navigator.share) {
        navigator.share({ url }).catch(() => {});
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(() => done('Link copied'), () => done(url));
      } else {
        done(url);
      }
    }
  });
  // A tapped notification while this site is open: the chat screen handles it itself
  // (chat.js); any other page (account, admin, …) simply goes to the message.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (e) => {
      if (e.data?.type !== 'open' || !e.data.url || document.body?.classList.contains('chat-app')) return;
      const u = new URL(e.data.url, location.origin);
      if (u.origin === location.origin) location.href = u.href;
    });
  }
  document.addEventListener('DOMContentLoaded', apply);
  // The ⋮ menu's "Appearance" item cycles Auto → Dark → Light.
  document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-theme-toggle]')) return;
    const next = { auto: 'dark', dark: 'light', light: 'auto' }[choice()];
    try { localStorage.setItem('theme', next); } catch (err) { /* storage unavailable */ }
    apply();
  });
})();
