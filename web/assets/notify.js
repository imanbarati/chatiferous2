// Install guide and notification settings (install.php, notifications.php).
(() => {
  const $ = (s) => document.querySelector(s);
  const ua = navigator.userAgent;
  const isIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isAndroid = /Android/.test(ua);
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

  // ---- Install page: show the steps for this device ----
  const installCard = $('#install-card');
  if (installCard) {
    const platform = isIOS ? 'ios' : isAndroid ? 'android' : 'desktop';
    for (const s of installCard.querySelectorAll('[data-platform]')) s.hidden = s.dataset.platform !== platform;
    if (standalone) $('#installed-note').hidden = false;
    let deferred = null;
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferred = e;
      const b = $('#android-button');
      if (b) b.hidden = false;
    });
    $('#install-now')?.addEventListener('click', async () => {
      if (!deferred) return;
      deferred.prompt();
      await deferred.userChoice;
      deferred = null;
    });
  }

  // ---- Notifications page ----
  const card = $('#push-card');
  if (!card) return;
  const base = card.dataset.base, csrf = card.dataset.csrf;
  const status = $('#push-status'), actions = $('#push-actions');

  const post = async (params) => {
    const res = await fetch(base + 'api/push.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': csrf }, body: new URLSearchParams(params) });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Something went wrong.');
    return data;
  };
  const b64 = (s) => {
    const pad = '='.repeat((4 - (s.length % 4)) % 4);
    const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
  };
  const device = () => (isIOS ? 'iPhone/iPad' : isAndroid ? 'Android' : 'Computer') + (standalone ? ' (app)' : ' (browser)');

  const show = (text, buttons = []) => {
    status.innerHTML = text;
    actions.innerHTML = '';
    for (const [label, fn, cls] of buttons) {
      const b = document.createElement('button');
      b.className = cls || 'primary';
      b.textContent = label;
      b.addEventListener('click', async () => {
        b.disabled = true;
        try { await fn(); } catch (e) { status.textContent = e.message; } finally { b.disabled = false; }
      });
      actions.appendChild(b);
    }
  };

  async function registration() {
    return navigator.serviceWorker.register(base + 'sw.php', { scope: base });
  }

  async function turnOn() {
    const perm = await Notification.requestPermission();   // must follow a tap, which it does
    if (perm !== 'granted') return refresh();
    const { public_key } = await (await fetch(base + 'api/push.php', { credentials: 'same-origin' })).json();
    const reg = await registration();
    await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(public_key) });
    const json = sub.toJSON();
    json.encoding = (PushManager.supportedContentEncodings || ['aes128gcm'])[0];
    await post({ action: 'subscribe', subscription: JSON.stringify(json), device: device() });
    return refresh(true);
  }

  async function turnOff() {
    const reg = await registration();
    const sub = await reg.pushManager.getSubscription();
    if (sub) {
      await post({ action: 'unsubscribe', endpoint: sub.endpoint });
      await sub.unsubscribe();
    }
    return refresh();
  }

  async function test() {
    const r = await post({ action: 'test' });
    status.innerHTML = r.sent ? '✓ Test sent. It should appear in a few seconds.' : 'The test couldn’t be delivered. Try turning notifications off and on again.';
  }

  async function refresh(justTurnedOn = false) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      if (isIOS && !standalone) {
        return show('On iPhone and iPad, notifications only work in the installed app. First <a href="' + base + 'install.php">add the group to your Home Screen</a>, then open it from the new icon and come back to this page.');
      }
      return show('This browser can’t show notifications. Try Chrome, Edge, Firefox or Safari.');
    }
    if (Notification.permission === 'denied') {
      return show('Notifications are <strong>blocked</strong> for this site on this device. To allow them, open your browser’s (or phone’s) settings for this site, set Notifications to <strong>Allow</strong>, then come back here.');
    }
    const reg = await registration();
    const sub = await reg.pushManager.getSubscription();
    if (sub && Notification.permission === 'granted') {
      // Re-send the subscription in case it changed (harmless if not).
      const json = sub.toJSON();
      json.encoding = (PushManager.supportedContentEncodings || ['aes128gcm'])[0];
      await post({ action: 'subscribe', subscription: JSON.stringify(json), device: device() });
      return show((justTurnedOn ? '🎉 ' : '') + '<strong>Notifications are on</strong> for this device.',
        [['Send me a test', test], ['Turn off on this device', turnOff, 'link danger']]);
    }
    return show('Notifications are <strong>off</strong> for this device. Turn them on to hear about new messages even when the site is closed.',
      [['Turn on notifications', turnOn]]);
  }

  // What to be notified about (saved immediately).
  $('#level-form')?.addEventListener('change', async (e) => {
    try { await post({ action: 'level', level: e.target.value }); } catch (err) { alert(err.message); }
  });

  refresh().catch((e) => { status.textContent = e.message; });
})();
