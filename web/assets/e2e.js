// End-to-end encryption for direct messages, using only the browser's built-in Web Crypto.
// Nothing readable about a DM's content ever reaches the server; nobody with the server, the
// site's owner included, can decrypt what's stored there.
//
//  - Each member has an ECDH P-256 key pair, made in their own browser. The server keeps the
//    public key, and the private key only in two locked forms: locked with their password
//    (PBKDF2, 600,000 rounds) and locked with their recovery code (which only they keep).
//  - Each conversation has its own AES-256-GCM key, stored once for each of its two members,
//    locked to that member's public key (an ephemeral ECDH key + HKDF + AES-GCM).
//  - Messages (text, formatting, quotes, file details) and files are sealed with the
//    conversation key before they leave the device.
//  - A device that has unlocked its key keeps it in IndexedDB, so the app and notifications
//    can open messages without asking again.
// Works in pages and in the service worker (self.E2E).
(() => {
  const C = self.crypto.subtle;
  const enc = new TextEncoder(), dec = new TextDecoder();
  const EC = { name: 'ECDH', namedCurve: 'P-256' };
  const PW_ROUNDS = 600000;       // each password guess is deliberately slow
  const RC_ROUNDS = 100000;       // recovery codes are long and random: guessing is hopeless anyway
  const PREFIX = 'e2e1:';

  const rand = (n) => self.crypto.getRandomValues(new Uint8Array(n));
  function b64(buf) {
    const a = new Uint8Array(buf);
    let s = '';
    for (let i = 0; i < a.length; i += 0x8000) s += String.fromCharCode.apply(null, a.subarray(i, i + 0x8000));
    return btoa(s);
  }
  const unb64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

  async function passwordKey(secret, salt, rounds) {
    const base = await C.importKey('raw', enc.encode(secret), 'PBKDF2', false, ['deriveKey']);
    return C.deriveKey({ name: 'PBKDF2', salt, iterations: rounds, hash: 'SHA-256' }, base,
      { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  }

  // The private key, locked with a secret (password or recovery code), as stored on the server.
  async function lockPrivate(privateKey, secret, rounds) {
    const salt = rand(16), iv = rand(12);
    const key = await passwordKey(secret, salt, rounds);
    const ct = await C.encrypt({ name: 'AES-GCM', iv }, key, await C.exportKey('pkcs8', privateKey));
    return JSON.stringify({ v: 1, salt: b64(salt), rounds, iv: b64(iv), ct: b64(ct) });
  }

  // Throws if the secret is wrong.
  async function unlockPrivate(locked, secret) {
    const o = JSON.parse(locked);
    const key = await passwordKey(secret, unb64(o.salt), o.rounds);
    const pkcs8 = await C.decrypt({ name: 'AES-GCM', iv: unb64(o.iv) }, key, unb64(o.ct));
    return C.importKey('pkcs8', pkcs8, EC, true, ['deriveBits']);
  }

  // Recovery codes: 160 random bits, as 8 groups of 4 letters/digits (no 0/1/8/9 look-alikes).
  const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  function newRecoveryCode() {
    const bytes = rand(20);
    let bits = '', out = '';
    for (const b of bytes) bits += b.toString(2).padStart(8, '0');
    for (let i = 0; i < bits.length; i += 5) out += B32[parseInt(bits.slice(i, i + 5), 2)];
    return out.match(/.{4}/g).join('-');
  }
  const normalizeCode = (code) => String(code).toUpperCase().replace(/[^A-Z2-7]/g, '');

  // A new key pair and recovery code, and the two locked copies to store.
  async function createKeys(password) {
    const pair = await C.generateKey(EC, true, ['deriveBits']);
    const code = newRecoveryCode();
    return {
      privateKey: pair.privateKey,
      publicKey: b64(await C.exportKey('spki', pair.publicKey)),
      lockedByPassword: await lockPrivate(pair.privateKey, password, PW_ROUNDS),
      lockedByCode: await lockPrivate(pair.privateKey, normalizeCode(code), RC_ROUNDS),
      code,
    };
  }

  const relockWithPassword = (privateKey, password) => lockPrivate(privateKey, password, PW_ROUNDS);
  async function relockWithNewCode(privateKey) {
    const code = newRecoveryCode();
    return { code, lockedByCode: await lockPrivate(privateKey, normalizeCode(code), RC_ROUNDS) };
  }
  const unlockWithCode = (locked, code) => unlockPrivate(locked, normalizeCode(code));

  async function kek(shared) {
    const k = await C.importKey('raw', shared, 'HKDF', false, ['deriveKey']);
    return C.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(32), info: enc.encode('chatiferous dm key v1') },
      k, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  }

  const newConversationKey = () => C.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);

  // A conversation key locked so that only the holder of this public key can open it.
  async function lockFor(conversationKey, publicKeyB64) {
    const pub = await C.importKey('spki', unb64(publicKeyB64), EC, false, []);
    const eph = await C.generateKey(EC, true, ['deriveBits']);
    const k = await kek(await C.deriveBits({ name: 'ECDH', public: pub }, eph.privateKey, 256));
    const iv = rand(12);
    const ct = await C.encrypt({ name: 'AES-GCM', iv }, k, await C.exportKey('raw', conversationKey));
    return JSON.stringify({ v: 1, epk: b64(await C.exportKey('spki', eph.publicKey)), iv: b64(iv), ct: b64(ct) });
  }

  async function openLocked(locked, privateKey) {
    const o = JSON.parse(locked);
    const epk = await C.importKey('spki', unb64(o.epk), EC, false, []);
    const k = await kek(await C.deriveBits({ name: 'ECDH', public: epk }, privateKey, 256));
    const raw = await C.decrypt({ name: 'AES-GCM', iv: unb64(o.iv) }, k, unb64(o.ct));
    return C.importKey('raw', raw, { name: 'AES-GCM' }, true, ['encrypt', 'decrypt']);
  }

  // A message's contents ({t: text, e: formatting, q: quote, a: file details}) <-> "e2e1:…".
  async function seal(ck, obj) {
    const iv = rand(12);
    const ct = new Uint8Array(await C.encrypt({ name: 'AES-GCM', iv }, ck, enc.encode(JSON.stringify(obj))));
    const out = new Uint8Array(12 + ct.length);
    out.set(iv);
    out.set(ct, 12);
    return PREFIX + b64(out);
  }
  async function open(ck, sealed) {
    const raw = unb64(sealed.slice(PREFIX.length));
    return JSON.parse(dec.decode(await C.decrypt({ name: 'AES-GCM', iv: raw.slice(0, 12) }, ck, raw.slice(12))));
  }
  const isSealed = (s) => typeof s === 'string' && s.startsWith(PREFIX);

  // Files: the bytes sealed the same way (12-byte nonce, then ciphertext).
  async function sealBytes(ck, buf) {
    const iv = rand(12);
    const ct = new Uint8Array(await C.encrypt({ name: 'AES-GCM', iv }, ck, buf));
    const out = new Uint8Array(12 + ct.length);
    out.set(iv);
    out.set(ct, 12);
    return out;
  }
  async function openBytes(ck, buf) {
    const a = new Uint8Array(buf);
    return C.decrypt({ name: 'AES-GCM', iv: a.slice(0, 12) }, ck, a.slice(12));
  }

  // ---- This device's unlocked key (IndexedDB) ----
  function db() {
    return new Promise((resolve, reject) => {
      const r = indexedDB.open('chatiferous-e2e', 1);
      r.onupgradeneeded = () => r.result.createObjectStore('keys');
      r.onsuccess = () => resolve(r.result);
      r.onerror = () => reject(r.error);
    });
  }
  async function tx(mode, fn) {
    const d = await db();
    return new Promise((resolve, reject) => {
      const t = d.transaction('keys', mode);
      const req = fn(t.objectStore('keys'));
      t.oncomplete = () => resolve(req?.result);
      t.onerror = () => reject(t.error);
    });
  }
  // {userId, version, privateKey}
  const saveDeviceKey = (rec) => tx('readwrite', (s) => s.put(rec, 'me'));
  const loadDeviceKey = () => tx('readonly', (s) => s.get('me')).catch(() => null);
  const forgetDeviceKey = () => tx('readwrite', (s) => s.delete('me')).catch(() => null);

  self.E2E = {
    createKeys, unlockPrivate, unlockWithCode, relockWithPassword, relockWithNewCode, normalizeCode,
    newConversationKey, lockFor, openLocked, seal, open, isSealed, sealBytes, openBytes,
    saveDeviceKey, loadDeviceKey, forgetDeviceKey,
  };
})();
