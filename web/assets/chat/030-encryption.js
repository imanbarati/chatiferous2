  // End-to-end encryption for direct messages: the browser side of SECURITY.md.
  //
  // Each person has an ECDH key pair; the private key is stored on the server only in locked copies
  // (one opened by their password, one by their recovery code), and unlocked here into IndexedDB.
  // Every conversation has its own AES key, locked separately for each of its two members.
  //
  //   e2eReady()     make sure this device's key is unlocked (asking, if it must)
  //   setupKeys()    first-time set-up; showRecoveryCode() shows the code once
  //   convKey(t)     the conversation's key, making and sharing it when needed
  //   openSealed()   decrypt messages for display; sealTyped() seals what you write
  //   openFile()     fetch and decrypt a sealed photo or file (loadSealedFiles for those on screen)
  //
  // The server never sees a key: anything sent to it is already sealed or locked.

  // A DM's text, formatting, quotes and files are sealed here, in the browser (assets/e2e.js),
  // with a key only its two members hold. The server never sees them or anything that opens them.

  const e2e = { rec: undefined, priv: null, cks: new Map(), pendingPw: null, busy: null, previews: new Map(), files: new Map() };

  // This member's key record from the server: version, public key and the two locked copies of
  // the private key. Cached, since nearly every DM action needs it.
  async function keyRecord(fresh) {
    if (e2e.rec === undefined || fresh) e2e.rec = (await api('keys.php')).keys;
    return e2e.rec;
  }
  // The password typed on the login page, handed over for a moment (never stored).
  function loginPassword() {
    try { const p = sessionStorage.getItem('pw-once'); sessionStorage.removeItem('pw-once'); return p; } catch (e) { return null; }
  }
  function oldPassword() {   // after a password change on the account page
    try { const p = sessionStorage.getItem('pw-old'); sessionStorage.removeItem('pw-old'); return p; } catch (e) { return null; }
  }
  // Holds the unlocked private key for this session and keeps it on the device for next time.
  async function useKey(privateKey, rec) {
    e2e.priv = privateKey;
    await E2E.saveDeviceKey({ userId: APP.me, version: rec.version, privateKey }).catch(() => {});
  }

  // Is this device ready to open DMs? Sets up or unlocks quietly when it can (right after a
  // password login); otherwise asks, if interactive. Resolves true when ready.
  function e2eReady(interactive = true) {
    if (e2e.priv) return Promise.resolve(true);
    e2e.busy ||= (async () => {
      const rec = await keyRecord();
      const dev = await E2E.loadDeviceKey();
      const pw = e2e.pendingPw || loginPassword();
      if (rec && dev && dev.userId === APP.me && dev.version === rec.version) {
        e2e.priv = dev.privateKey;
        if (pw) await relockIfNeeded(rec, pw);   // password was reset: this device re-locks the key under the new one
        e2e.pendingPw = null;
        return true;
      }
      if (pw) {
        e2e.pendingPw = pw;
        if (!rec) { await setupKeys(pw); return true; }
        try { await useKey(await E2E.unlockPrivate(rec.locked_pw, pw), rec); e2e.pendingPw = null; return true; } catch (e) { /* a new password */ }
        const old = oldPassword();
        if (old) {   // just changed it: open with the old one, lock with the new one
          try { await useKey(await E2E.unlockPrivate(rec.locked_pw, old), rec); await relockIfNeeded(rec, pw); e2e.pendingPw = null; return true; } catch (e) { /* no */ }
        }
      }
      return false;
    })().finally(() => { e2e.busy = null; });
    return e2e.busy.then(async (ok) => (ok || !interactive ? ok : keyDialog(await keyRecord())));
  }

  // After a password change, lock the private key under the new password (the old locked copy
  // would no longer open). Only a device that already holds the key can do this.
  async function relockIfNeeded(rec, pw) {
    try { await E2E.unlockPrivate(rec.locked_pw, pw); } catch (e) {
      const locked = await E2E.relockWithPassword(e2e.priv, pw);
      await api('keys.php', { action: 'relock', locked_pw: locked }, true);
      rec.locked_pw = locked;
    }
  }

  // Asks the server whether this really is the member's password, before setting keys up with it.
  async function isMyPassword(pw) {
    return (await api('keys.php', { action: 'check', password: pw }, true)).ok;
  }

  // First set-up (or a fresh start): make the key pair, lock it with the password and a new
  // recovery code, and send only the locked copies up. Returns the code, to show once.
  async function setupKeys(password, fresh = false) {
    if (!(await isMyPassword(password))) throw new Error('That isn’t your password.');
    const k = await E2E.createKeys(password);
    const r = await api('keys.php', { action: 'setup', public_key: k.publicKey, locked_pw: k.lockedByPassword, locked_code: k.lockedByCode, fresh: fresh ? 1 : 0 }, true);
    e2e.rec = { version: r.version, public_key: k.publicKey, locked_pw: k.lockedByPassword, locked_code: k.lockedByCode };
    await useKey(k.privateKey, e2e.rec);
    e2e.pendingPw = null;
    e2e.cks.clear();
    showRecoveryCode(k.code, fresh);
  }

  // A plain dialog box for the key screens.
  function modal(cls, html) {
    const el = document.createElement('div');
    el.className = 'modal gif-modal ' + cls;
    el.innerHTML = `<div class="sheet-card keys-sheet" role="dialog">${html}</div>`;
    document.body.appendChild(el);
    return el;
  }

  // The recovery code, shown once: save it in the password manager, copy it, or write it down.
  function showRecoveryCode(code, fresh) {
    // Chrome/Android can save it straight into the password manager; iPhone browsers can't hand
    // it to one (Enpass, 1Password, Keychain), so there it's Copy and paste.
    const canStore = !!(window.PasswordCredential && navigator.credentials?.store);
    const label = `${(state.users[APP.me] || {}).username || 'me'} (${APP.short} message recovery code)`;
    const el = modal('code-modal', `
      <h2>🔒 ${fresh ? 'New keys made' : 'Your messages are private'}</h2>
      <p>Your direct messages are <strong>end-to-end encrypted</strong>: only you and the person you’re writing to can read them. Not the site’s owner, not anyone.</p>
      <p>This is your <strong>recovery code</strong>. If you ever forget your password, it reopens your messages. Keep it somewhere safe.
        <strong>This is the one and only time we’ll show you this.</strong></p>
      <div class="recovery-code" translate="no">${esc(code)}</div>
      ${canStore
        ? `<button type="button" class="primary rc-store">Save in my password manager</button><button type="button" class="rc-copy copy-btn">📋 Copy code</button>`
        : `<button type="button" class="primary rc-copy">📋 Copy code</button>`}
      <p class="small muted">${canStore ? 'Or copy it, or write it down.' : 'Then paste it into your password manager (for example, a new secure note in Enpass or 1Password called “${esc(APP.short)} message recovery code”), or write it down.'}
        The people you message can also restore your access, one conversation at a time.</p>
      <button type="button" class="rc-done done-btn">I’ve saved it</button>`);
    el.addEventListener('click', async (e) => {
      if (e.target.closest('.rc-store')) {
        try {
          await navigator.credentials.store(new PasswordCredential({ id: label, password: code, name: `${APP.short} message recovery code` }));
          e.target.closest('.rc-store').textContent = '✓ Offered to your password manager';
        } catch (err) { toast('Your browser didn’t save it. Please copy it instead.'); }
      }
      const cp = e.target.closest('.rc-copy');
      if (cp) {
        let ok = false;
        try { await navigator.clipboard.writeText(code); ok = true; } catch (err) {
          // Older browsers: copy from a temporary selection.
          const t = document.createElement('textarea');
          t.value = code;
          t.setAttribute('readonly', '');
          document.body.appendChild(t);
          t.select();
          try { ok = document.execCommand('copy'); } catch (x) { ok = false; }
          t.remove();
        }
        cp.textContent = ok ? '✓ Copied' : 'Couldn’t copy: please write it down';
        if (ok) setTimeout(() => { cp.textContent = '📋 Copy code'; }, 2500);
      }
      if (e.target.closest('.rc-done')) el.remove();
    });
  }

  // Set up, or unlock this device (password, recovery code, or start fresh). Resolves true when ready.
  function keyDialog(rec) {
    return new Promise((resolve) => {
      const el = modal('unlock-modal', rec ? `
          <h2>🔒 Unlock your messages</h2>
          <p>Your direct messages are end-to-end encrypted. Enter your password to open them on this device (once).</p>
          <p class="small muted">That’s your password for this site, the one you chose with your username${APP.telegram ? ', not anything from Telegram' : ''}.${APP.signinNote ? ' ' + esc(APP.signinNote) : ''}</p>
          <form class="k-pw"><input type="password" class="k-in" autocomplete="current-password" placeholder="Your password" required><button class="primary">Unlock</button></form>
          <form class="k-code" hidden><input class="k-codein" placeholder="Recovery code, e.g. ABCD-EFGH-…" autocomplete="off" autocapitalize="characters" required>
            <input type="password" class="k-newpw" autocomplete="current-password" placeholder="Your current password" required><button class="primary">Unlock</button></form>
          <p class="k-err small" hidden></p>
          <button class="link k-usecode">Forgot your password? Use your recovery code</button>
          <button class="link k-fresh">Lost both? Start fresh</button>
          <button class="link k-later">Not now</button>` : `
          <h2>🔒 Private messages</h2>
          <p>Direct messages are end-to-end encrypted: only you and the person you’re writing to can read them. To set this up, enter your password.</p>
          <p class="small muted">That’s your password for this site, the one you chose with your username${APP.telegram ? ', not anything from Telegram' : ''}.${APP.signinNote ? ' ' + esc(APP.signinNote) : ''} Forgotten it? An admin can send you a reset link.</p>
          <form class="k-setup"><input type="password" class="k-in" autocomplete="current-password" placeholder="Your password" required><button class="primary">Set up</button></form>
          <p class="k-err small" hidden></p>
          <button class="link k-later">Not now</button>`);
      const err = (t) => { const p = $('.k-err', el); p.hidden = !t; p.textContent = t || ''; };
      const done = (ok) => { el.remove(); resolve(ok); };
      const busy = (f, on) => { const b = $('button.primary', f); b.disabled = on; b.textContent = on ? 'One moment…' : b.dataset.label || (b.dataset.label = b.textContent); };
      el.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        busy(f, true);
        err('');
        try {
          if (f.classList.contains('k-setup')) {
            await setupKeys($('.k-in', f).value);
          } else if (f.classList.contains('k-pw')) {
            await useKey(await E2E.unlockPrivate(rec.locked_pw, $('.k-in', f).value), rec).catch(() => { throw new Error('That password didn’t open them. If you changed your password recently, use your recovery code.'); });
          } else if (f.classList.contains('k-code')) {
            const key = await E2E.unlockWithCode(rec.locked_code, $('.k-codein', f).value).catch(() => { throw new Error('That recovery code isn’t right.'); });
            if (!(await isMyPassword($('.k-newpw', f).value))) throw new Error('That isn’t your current password.');
            await useKey(key, rec);
            await relockIfNeeded(rec, $('.k-newpw', f).value);   // from now on your current password opens them
            toast('Unlocked. Your password opens your messages again.');
          } else if (f.classList.contains('k-freshform')) {
            await setupKeys($('.k-in', f).value, true);
          }
          e2e.cks.clear();
          done(true);
        } catch (x) { err(x.message); busy(f, false); }
      });
      el.addEventListener('click', (e) => {
        if (e.target.closest('.k-later')) done(false);
        if (e.target.closest('.k-usecode')) { $('.k-pw', el).hidden = true; $('.k-code', el).hidden = false; e.target.hidden = true; }
        if (e.target.closest('.k-fresh')) {
          if (!confirm('Start fresh with new keys? Your earlier conversations stay locked until each person you’ve messaged restores your access (they’ll be asked automatically).')) return;
          el.querySelectorAll('form').forEach((f) => { f.hidden = true; });
          el.querySelector('.k-usecode').hidden = true;
          e.target.hidden = true;
          el.querySelector('.k-err').insertAdjacentHTML('beforebegin', '<form class="k-freshform"><input type="password" class="k-in" autocomplete="current-password" placeholder="Your current password" required><button class="primary">Make new keys</button></form>');
        }
      });
    });
  }

  // A conversation's key: opened from my locked copy, or made (and locked for both) if it's new.
  async function convKey(t, interactive = true) {
    if (e2e.cks.has(t.id)) {
      const ck = e2e.cks.get(t.id);
      if (t.keys?.partner_needs === 'share') shareKey(t, ck);
      return ck;
    }
    if (!(await e2eReady(interactive))) return null;
    const ks = t.keys || (t.keys = {});
    let ck = null;
    if (ks.lock) {
      try { ck = await E2E.openLocked(ks.lock, e2e.priv); } catch (e) { ck = null; }
    } else if (!ks.has_key) {
      ck = await E2E.newConversationKey();
      const rec = await keyRecord();
      await api('keys.php', { action: 'dm_key', topic: t.id, for: APP.me, locked: await E2E.lockFor(ck, rec.public_key) }, true);
      ks.has_key = true;
      if (ks.partner_key) {
        await api('keys.php', { action: 'dm_key', topic: t.id, for: t.partner, locked: await E2E.lockFor(ck, ks.partner_key.key) }, true);
        ks.partner_needs = null;
      }
    }
    if (ck) {
      e2e.cks.set(t.id, ck);
      if (ks.partner_needs === 'share') shareKey(t, ck);   // they set up after the conversation began
    }
    return ck;
  }

  // Lock this conversation's key for the other person (first share, or restoring their access).
  async function shareKey(t, ck) {
    const ks = t.keys;
    if (!ks?.partner_key) return;
    await api('keys.php', { action: 'dm_key', topic: t.id, for: t.partner, locked: await E2E.lockFor(ck, ks.partner_key.key) }, true).catch(() => {});
    ks.partner_needs = null;
  }

  // What a message says, opened. Locked messages say so.
  const payloadPreview = (p) => {
    if (!p || p.locked) return '🔒 Encrypted message';
    if (p.broken) return 'Message couldn’t be opened';
    const a = (p.a || [])[0];
    return (p.t || '').replace(/\s+/g, ' ').slice(0, 160) || (a ? (a.kind === 'photo' ? '🖼 Photo' : a.kind === 'animation' ? 'GIF' : a.kind === 'video' ? '📹 Video' : '📎 ' + (a.name || 'File')) : '');
  };

  // Opens the sealed messages (and the reply headers quoting them) of a DM, in place (m._p).
  async function openSealed(t, list, interactive = true) {
    if (t.kind !== 'dm') return;
    const needs = list.filter((m) => (E2E.isSealed(m.text) && (!m._p || m._p.locked)) || (m.reply?.sealed && !m.reply._opened));
    if (!needs.length) return;
    const ck = await convKey(t, interactive);
    for (const m of needs) {
      if (E2E.isSealed(m.text)) {
        if (!ck) m._p = { locked: true };
        else { try { m._p = await E2E.open(ck, m.text); } catch (e) { m._p = { broken: true }; } }
      }
      if (m.reply?.sealed && ck) {
        try { m.reply.text = payloadPreview(await E2E.open(ck, m.reply.sealed)); m.reply._opened = true; } catch (e) { m.reply.text = '🔒'; }
      }
    }
  }
  // Pinned messages in a conversation arrive sealed; open them so the pin bar can show them.
  async function openPins(t, pins) {
    if (t.kind !== 'dm' || !pins?.some((p) => p.sealed)) return;
    const ck = await convKey(t, false);
    for (const p of pins) {
      if (p.sealed) { try { p.text = ck ? payloadPreview(await E2E.open(ck, p.sealed)) : '🔒 Encrypted message'; } catch (e) { p.text = '🔒'; } }
    }
  }

  // A DM message as the rest of the app sees it: its opened text, formatting, quote and files.
  function plainView(m) {
    const p = m?._p;
    if (!p) return m;
    if (p.locked || p.broken) {
      return { ...m, text: p.locked ? '🔒 Waiting for access to this conversation' : '⚠ This message couldn’t be opened', ents: [], att: [] };
    }
    return {
      ...m,
      text: typeof p.t === 'string' ? p.t : '',
      ents: Array.isArray(p.e) ? p.e : [],
      att: Array.isArray(p.a) ? p.a.map(cleanSealedAtt).filter(Boolean) : m.att,
      reply: m.reply && typeof p.q === 'string' && p.q ? { ...m.reply, quote: p.q } : m.reply,
    };
  }
  // A sealed message's contents come from the sender's device, so they're checked like any other
  // input before they reach the page: whole numbers, known kinds, plain strings.
  function cleanSealedAtt(a) {
    if (!a || typeof a !== 'object') return null;
    const int = (v, max) => { const n = Math.floor(Number(v)); return n > 0 && n <= max ? n : 0; };
    const str = (v, max) => (typeof v === 'string' ? v.slice(0, max) : '');
    const id = int(a.id, 2 ** 31);
    if (!id) return null;
    const mime = str(a.mime, 100);
    return {
      id,
      kind: ['photo', 'video', 'animation', 'voice', 'file'].includes(a.kind) ? a.kind : 'file',
      mime: /^[\w.+-]+\/[\w.+-]+$/.test(mime) ? mime : '',
      name: str(a.name, 255),
      size: int(a.size, 2 ** 40),
      w: int(a.w, 20000),
      h: int(a.h, 20000),
      plain: a.plain === true,
      available: true,
      sealed: a.plain !== true,
    };
  }

  // Sealed files: fetched and opened on this device, then shown (or saved) from memory.
  const WAIT_IMG = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
  // A sealed photo or file, fetched and decrypted here. Kept for the session, since the same
  // picture is usually shown more than once.
  async function openFile(id) {
    if (e2e.files.has(id)) return e2e.files.get(id);
    const v = state.view, ck = v && await convKey(v.topic, false);
    if (!ck) throw new Error('locked');
    const res = await fetch(`${APP.base}file.php?id=${id}`, { credentials: 'same-origin' });
    if (!res.ok) throw new Error('missing');
    return new Uint8Array(await E2E.openBytes(ck, await res.arrayBuffer()));
  }
  // Fills in the placeholders left for sealed photos, videos and GIFs once they're decrypted.
  async function loadSealedFiles() {
    for (const el of chatEl.querySelectorAll('[data-sealed]:not(.loading-file)')) {
      el.classList.add('loading-file');
      const id = +el.dataset.sealed, kind = el.dataset.kind, mime = el.dataset.mime || 'application/octet-stream';
      try {
        let url = e2e.files.get(id);
        if (!url) {
          url = URL.createObjectURL(new Blob([await openFile(id)], { type: mime }));
          e2e.files.set(id, url);
        }
        const w = +el.getAttribute('width') || 4, h = +el.getAttribute('height') || 3;
        el.outerHTML = kind === 'video'
          ? `<video class="att-video" src="${url}" width="${w}" height="${h}" controls playsinline preload="metadata"></video>`
          : kind === 'animation' && /^video\//.test(mime)
            ? `<video class="att-video gif" src="${url}" width="${w}" height="${h}" muted autoplay loop playsinline></video>`
            : `<button class="att-photo" data-photo="${url}"><img src="${url}" width="${w}" height="${h}" alt="Photo"></button>`;
      } catch (e) { el.classList.remove('loading-file'); el.classList.add('file-failed'); }
    }
  }
  // Saving a sealed file to the phone or computer: decrypt first, then hand it over under its
  // real name.
  async function saveSealedFile(id, name, mime) {
    try {
      let url = e2e.files.get(id);
      if (!url) { url = URL.createObjectURL(new Blob([await openFile(id)], { type: mime || 'application/octet-stream' })); e2e.files.set(id, url); }
      const a = document.createElement('a');
      a.href = url;
      a.download = name || 'file';
      document.body.appendChild(a);
      a.click();
      a.remove();
    } catch (e) { toast('That file couldn’t be opened on this device.'); }
  }

  // A file for a DM: photos are resized and stripped of hidden data (location etc.) here, since
  // the server can no longer do it; then everything is sealed before upload.
  async function sealForUpload(file, ck) {
    let blob = file, meta = { kind: 'file', mime: file.type || 'application/octet-stream', name: file.name || 'file', size: file.size };
    const photo = /^image\/(jpeg|png|webp|heic|heif|avif|bmp)$/.test(file.type) || /\.(heic|heif)$/i.test(file.name || '');
    if (photo || file.type === 'image/gif') {
      try {
        const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
        if (file.type === 'image/gif') meta = { kind: 'photo', mime: 'image/gif', name: '', size: file.size, w: bmp.width, h: bmp.height };
        else {
          const scale = Math.min(1, 2560 / Math.max(bmp.width, bmp.height));
          const c = document.createElement('canvas');
          c.width = Math.round(bmp.width * scale);
          c.height = Math.round(bmp.height * scale);
          c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
          blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.86));
          meta = { kind: 'photo', mime: 'image/jpeg', name: '', size: blob.size, w: c.width, h: c.height };
        }
      } catch (e) { if (photo) throw new Error('That photo couldn’t be read. Please try another.'); }
    } else if (file.type.startsWith('video/')) {
      meta.kind = 'video';
      try {
        const dims = await new Promise((resolve, reject) => {
          const vid = document.createElement('video');
          vid.preload = 'metadata';
          vid.onloadedmetadata = () => { resolve([vid.videoWidth, vid.videoHeight]); URL.revokeObjectURL(vid.src); };
          vid.onerror = reject;
          vid.src = URL.createObjectURL(file);
        });
        [meta.w, meta.h] = dims;
      } catch (e) { /* no size: shown at a default shape */ }
    }
    return { sealed: new Blob([await E2E.sealBytes(ck, await blob.arrayBuffer())]), meta };
  }

  // Search inside a DM, on this device (only it can read the messages).
  async function searchDm(t, q) {
    const terms = (q.toLowerCase().match(/[\p{L}\p{N}]{2,}/gu) || []).slice(0, 8);
    if (!terms.length) return { results: [], terms };
    const data = await api('messages.php', { topic: t.id, mode: 'all' });
    await openSealed({ ...data.topic, keys: data.topic.keys }, data.messages);
    Object.assign(state.users, data.users || {});
    const results = [];
    for (const m of [...data.messages].reverse()) {
      const text = plainView(m).text || '';
      const low = text.toLowerCase();
      if (!terms.every((w) => low.includes(w))) continue;
      const u = state.users[m.user_id] || { name: '', color: 0 };
      const i = Math.max(0, low.indexOf(terms[0]) - 60);
      results.push({ id: m.id, topic: t.id, name: u.name, color: u.color, at: m.at, snippet: (i ? '…' : '') + text.slice(i, i + 160) + (text.length > i + 160 ? '…' : '') });
    }
    return { results, terms, more: false };
  }

  // What you typed, sealed for this DM. (fixed: a ready-made {t, e} instead of typed text.)
  async function sealTyped(v, input, extra = {}, fixed = null) {
    const ck = await convKey(v.topic);
    if (!ck) throw new Error('This conversation is still locked on this device.');
    const f = fixed ? { text: fixed.t, ents: fixed.e } : Compose.format(input, state.mentionPicks, usernameMap());
    if (f.text.length > Compose.MAX_CHARS) throw new Error('Messages can be up to 4096 characters.');
    const payload = { t: f.text, e: f.ents, ...extra };
    return { payload, sealed: await E2E.seal(ck, payload) };
  }
  // Username to member id, for turning @names into links while sealing a message.
  function usernameMap() {
    const m = new Map();
    for (const u of state.members || []) if (u.username) m.set(u.username.toLowerCase(), u.id);
    return m;
  }

  // Uploads a file (already sealed, for a DM, or plain). Resolves {id}.
  async function uploadBlob(blob, name, sealed) {
    const form = new FormData();
    form.append('file', blob, sealed ? 'sealed' : name);
    if (sealed) form.append('sealed', '1');
    const res = await fetch(APP.base + 'api/upload.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf }, body: form });
    const data = await res.json();
    if (!res.ok || !data.id) throw new Error(data.error || 'The upload failed.');
    return data;
  }

  // Forwarding a DM message: this device opens it and sends it again, sealed for a DM or plain
  // for a topic, with its files re-sent the same way, credited "Forwarded from".
  async function forwardFromDm(src, fromTopic, target, comment) {
    const p = src._p || {};
    const fromCk = await convKey(fromTopic, false);
    if (!fromCk) throw new Error('That message is locked on this device.');
    const toCk = target.kind === 'dm' ? await convKey(target) : null;
    if (target.kind === 'dm' && !toCk) throw new Error('This conversation is still locked on this device.');
    const sealedFiles = [], plainIds = [];
    for (const a of p.a || []) {
      if (a.plain) continue;   // (a GIF from the picker isn't carried along)
      const res = await fetch(`${APP.base}file.php?id=${a.id}`, { credentials: 'same-origin' });
      const bytes = new Uint8Array(await E2E.openBytes(fromCk, await res.arrayBuffer()));
      if (toCk) sealedFiles.push({ ...a, id: (await uploadBlob(new Blob([await E2E.sealBytes(toCk, bytes)]), '', true)).id });
      else plainIds.push((await uploadBlob(new Blob([bytes], { type: a.mime }), a.name || (a.kind === 'photo' ? 'photo.jpg' : 'file'), false)).id);
    }
    const from = src.fwd || (state.users[src.user_id] || {}).name || '';
    const v = { topic: target };
    if (comment.trim()) {
      await api('send.php', toCk ? { topic: target.id, text: (await sealTyped(v, comment)).sealed } : { topic: target.id, text: comment }, true);
    }
    if (toCk) {
      const payload = { t: p.t || '', e: p.e || [], ...(sealedFiles.length ? { a: sealedFiles } : {}) };
      const data = await api('send.php', { topic: target.id, text: await E2E.seal(toCk, payload), attachments: sealedFiles.map((f) => f.id).join(','), fwd_id: src.id, fwd_from: from }, true);
      data.message._p = payload;
      return data;
    }
    const [md] = toMarkdown(p.t || '', p.e || []);
    return api('send.php', { topic: target.id, text: md, attachments: plainIds.join(','), fwd_id: src.id, fwd_from: from }, true);
  }

  // The conversation list's previews, opened here (quietly: no prompts while listing).
  async function openDmPreviews() {
    for (const d of state.dms) {
      const s = d.last?.sealed;
      if (!s) continue;
      if (e2e.previews.has(d.last.id)) { d.last.preview = e2e.previews.get(d.last.id); continue; }
      const ck = await convKey({ id: d.id, kind: 'dm', partner: d.partner, keys: d.keys }, false).catch(() => null);
      if (ck && d.keys?.partner_needs === 'share') shareKey({ id: d.id, partner: d.partner, keys: d.keys }, ck);
      if (!ck) { d.last.preview = '🔒 Encrypted message'; continue; }
      try { d.last.preview = payloadPreview(await E2E.open(ck, s)); e2e.previews.set(d.last.id, d.last.preview); } catch (e) { d.last.preview = '🔒 Encrypted message'; }
    }
  }
