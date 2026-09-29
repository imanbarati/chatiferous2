  // First visit: the banner offering to install the app and turn on notifications. Then the phone
  // keyboard handling, which keeps the message box (and any open dialog) above the keyboard: Safari
  // shrinks only the "visual viewport", so the app is sized to that while the keyboard is up.

  // The one-time banner for new members: install the app on your phone, then turn notifications
  // on. It remembers what's been done and what was dismissed.
  async function setupBanner() {
    let dismissed = '';
    try { dismissed = localStorage.getItem('setup-banner') || ''; } catch (e) { /* ignore */ }
    const ua = navigator.userAgent;
    const isIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const phone = isIOS || /Android/.test(ua);
    const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    let pushOn = false;
    if ('serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && Notification.permission === 'granted') {
      const reg = await navigator.serviceWorker.getRegistration(APP.base);
      pushOn = !!(reg && await reg.pushManager.getSubscription());
    }
    let key, html;
    if (phone && !standalone) {
      key = 'install';
      html = `📲 <span><strong>Add the group to your Home Screen</strong> for one-tap access${isIOS ? ' (and, on iPhone, notifications)' : ' and notifications'}.</span> <a class="banner-go" href="${APP.base}install.php">Show me how</a>`;
    } else if (!phone && !pushOn) {
      key = 'desktop';
      const mac = /Mac/.test(navigator.platform);
      html = `🔖 <span><strong>Bookmark this page</strong> (${mac ? '⌘D' : 'Ctrl+D'}) and turn on notifications so you don’t miss new messages.</span> <a class="banner-go" href="${APP.base}notifications.php">Turn on notifications</a>`;
    } else if (!pushOn && 'PushManager' in window) {
      key = 'push';
      html = `🔔 <span><strong>Turn on notifications</strong> to hear about new messages.</span> <a class="banner-go" href="${APP.base}notifications.php">Set up</a>`;
    }
    if (!key || dismissed === key) return;
    const el = document.createElement('div');
    el.className = 'setup-banner';
    el.innerHTML = html + '<button class="banner-x" aria-label="Dismiss">✕</button>';
    el.querySelector('.banner-x').addEventListener('click', () => {
      try { localStorage.setItem('setup-banner', key); } catch (e) { /* ignore */ }
      el.remove();
    });
    topicsEl.before(el);
  }

  // ---------- Phones: keep the message box above the keyboard ----------
  // Safari shrinks only the "visual viewport" when the keyboard opens, so size the
  // app to it. Also undoes a standalone-mode bug where the page stays shrunk.
  // Only while the keyboard is up: iOS (especially the installed app) can report
  // a too-small viewport at other times, which would clip the page.
  if (window.visualViewport) {
    const root = document.documentElement, vv = window.visualViewport;
    const fit = () => {
      // A dialog (new poll, topic settings…) with the keyboard up: fit it to the part of the
      // screen the keyboard leaves, so its fields can all be scrolled to.
      const inDialog = document.activeElement?.closest?.('.modal');
      const kbUp = window.innerHeight - vv.height > 120;
      for (const m of document.querySelectorAll('.modal')) {
        if (inDialog && kbUp) {
          m.classList.add('kb');
          m.style.top = vv.offsetTop + 'px';
          m.style.height = vv.height + 'px';
        } else {
          m.classList.remove('kb');
          m.style.top = m.style.height = '';
        }
      }
      if (inDialog && kbUp) document.activeElement.scrollIntoView({ block: 'nearest' });
      const typing = document.activeElement?.tagName === 'TEXTAREA';
      const keyboard = typing && kbUp;
      if (keyboard) {
        root.style.setProperty('--app-h', vv.height + 'px');
        // iOS also scrolls the page up to reach the box you tapped, which pushes the (now
        // shorter) app's message box out of sight until you type. Undo that shift.
        if (window.scrollY || vv.offsetTop) window.scrollTo(0, 0);
      } else {
        root.style.removeProperty('--app-h');
        window.scrollTo(0, 0);   // undo iOS's leftover shift after the keyboard closes
      }
    };
    vv.addEventListener('resize', fit);
    vv.addEventListener('scroll', fit);
    // The keyboard slides in over a moment and its final size arrives late: check again.
    document.addEventListener('focusin', () => { fit(); setTimeout(fit, 250); setTimeout(fit, 600); });
    document.addEventListener('focusout', () => setTimeout(fit, 50));
  }

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(APP.base + 'sw.php', { scope: APP.base }).catch(() => {});
    // A tapped notification: jump to its topic and message.
    navigator.serviceWorker.addEventListener('message', (e) => {
      if (e.data?.type !== 'open' || !e.data.url) return;
      const u = new URL(e.data.url, location.origin);
      if (!u.pathname.startsWith(APP.base)) return;
      saveDraft();
      history.pushState({}, '', u.pathname);
      state.view = null;
      route();
    });
  }
  setupBanner().catch(() => {});
  // A verse quoted from the reader is waiting: ask where to send it (once the lists are loaded).
  setTimeout(() => { try { pendingBibleQuote(); } catch (e) { /* nothing waiting */ } }, 400);
