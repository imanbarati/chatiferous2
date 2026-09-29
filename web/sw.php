<?php
// The service worker (served by PHP so updates aren't held back by the host's proxy cache).
// It shows push notifications and opens the right topic when one is tapped.
require __DIR__ . '/boot.php';
require_once APP_DIR . '/lib/api_bible.php';
header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('Service-Worker-Allowed: ' . config('base_url'));
?>
// Service worker.
// Direct messages arrive sealed; this device's key (kept by the app in IndexedDB) opens them.
importScripts('a.php?f=e2e.js&v=<?= (int)filemtime(__DIR__ . '/assets/e2e.js') ?>');
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

// Pages load from the network as usual. What is kept, for when there is no signal, is only what
// somebody has already looked at: the app's own scripts and styles, the last chat page and the last
// reader page as shells to hang the local copy on, each day's reading on its own, and the answers
// from api/ that the reader needs to show a chapter or a commentary again.
//
// The licensed translations are never kept. They are fetched from their publishers a chapter at a
// time and shown; keeping a copy on a device would be serving them offline, which we do not do.
const SHELL = 'chatiferous-shell';   // scripts, styles, and a shell for each half of the app
const PAGES = 'chatiferous-pages';   // whole pages worth having again: the daily readings
const DATA  = 'chatiferous-data';    // answers from api/ behind the reader

const BASE = <?= json_encode(config('base_url')) ?>;
const LICENSED = <?= json_encode(array_keys(api_bible_map()), JSON_UNESCAPED_UNICODE) ?>;

const isReader  = (p) => p.includes('/read/') || p.endsWith('/bible.php');
const isReading = (p) => /\/reading(\/\d{4}-\d{2}-\d{2})?\/?$/.test(p) || p.endsWith('/reading.php');
const isChat    = (p) => p === BASE || /\/(t\/\d+(\/\d+)?|messages)\/?$/.test(p) || p.endsWith('/index.php');

// A page is kept under its address without the query, so a link carrying anything extra still
// finds it. The reader is the exception: there the version is the point.
const pageKey = (url) => url.origin + url.pathname;

// Whether this request is for one of the translations we may show but not keep.
const licensed = (url) => LICENSED.includes((url.searchParams.get('v') || '').toUpperCase());

function offlinePage() {
  return new Response(
    '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>'
    + '<body style="font:18px system-ui;text-align:center;padding:4em 1em;color:#555">You\u2019re offline.<br>'
    + 'This page hasn\u2019t been opened on this device yet.</body>',
    { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // Assets (a.php?f=…&v=…) never change for a given version: keep the first copy.
  if (url.pathname.endsWith('/a.php')) {
    e.respondWith(caches.open(SHELL).then(async (c) => {
      const hit = await c.match(e.request);
      if (hit) return hit;
      const res = await fetch(e.request);
      if (res.ok) {
        // A new version of a file replaces the copies of its older ones.
        const f = url.searchParams.get('f');
        for (const k of await c.keys()) {
          const u = new URL(k.url);
          if (u.pathname.endsWith('/a.php') && u.searchParams.get('f') === f) c.delete(k);
        }
        c.put(e.request, res.clone());
      }
      return res;
    }).catch(() => fetch(e.request)));
    return;
  }

  // What the reader asks for behind the scenes: a chapter, a commentary, cross-references. Fresh
  // when there is a signal, and from the last copy when there isn't.
  if (e.request.method === 'GET' && /\/api\/(bible|commentary)\.php$/.test(url.pathname)) {
    e.respondWith((async () => {
      try {
        const res = await fetch(e.request);
        if (res.ok && !licensed(url)) (await caches.open(DATA)).put(e.request, res.clone());
        return res;
      } catch (err) {
        const hit = await caches.match(e.request);
        if (hit) return hit;
        throw err;
      }
    })());
    return;
  }

  if (e.request.mode !== 'navigate') return;

  const reader = isReader(url.pathname), reading = isReading(url.pathname), chat = isChat(url.pathname);

  // What to fall back to: the day itself, then the shell for whichever half of the app this is.
  const fallback = async () => {
    if (reading) {
      const day = await caches.match(pageKey(url));
      if (day) return day;
    }
    if (reader) {
      const shell = await caches.match('reader-shell');
      if (shell) return shell;                 // the chapter itself comes from this device's copy
    }
    if (chat) {
      const shell = await caches.match('chat-shell');
      if (shell) return shell;                 // the messages come from this device's copy
    }
    return null;
  };

  e.respondWith((async () => {
    try {
      const res = await fetch(e.request);
      if (res.ok) {
        const c = await caches.open(reading ? PAGES : SHELL);
        if (reading) c.put(pageKey(url), res.clone());
        else if (reader && !licensed(url)) c.put('reader-shell', res.clone());
        else if (chat) c.put('chat-shell', res.clone());
        return res;
      }
      // The host answered, but not with the app: too many requests, or a gateway having a bad
      // moment. Its error page is no use to anybody, so show what this device kept instead — but
      // never for 401 or 403, where being sent to sign in is the whole point.
      if (res.status === 429 || res.status >= 500) {
        const kept = await fallback();
        if (kept) return kept;
      }
      return res;
    } catch (err) {
      return (await fallback()) || offlinePage();
    }
  })());
});

// Keeping the past week's readings, asked for by the app once it has nothing more pressing to do.
// One at a time, with a pause between, and abandoned at the first refusal: this is a convenience,
// and it must never be in the way of the page somebody is actually reading.
self.addEventListener('message', (e) => {
  if (e.data?.type !== 'warm' || !Array.isArray(e.data.urls)) return;
  e.waitUntil((async () => {
    const pages = await caches.open(PAGES);
    const data = await caches.open(DATA);
    for (const u of e.data.urls.slice(0, 24)) {
      let url;
      try { url = new URL(u, self.location.origin); } catch (err) { continue; }
      if (url.origin !== self.location.origin) continue;
      // Two kinds are warmed: a day's reading, kept under its own address, and the commentary on
      // a chapter of it, kept exactly as the reader will ask for it later.
      const comment = /\/api\/commentary\.php$/.test(url.pathname);
      if (!comment && !isReading(url.pathname)) continue;
      const jar = comment ? data : pages;
      const key = comment ? url.href : pageKey(url);
      if (await jar.match(key)) continue;                     // already on this device
      try {
        const res = await fetch(url.href, { credentials: 'same-origin' });
        if (!res.ok) continue;
        await jar.put(key, res.clone());
      } catch (err) {
        break;                                               // no signal: leave it for another day
      }
      await new Promise((r) => setTimeout(r, 1500));
    }
  })());
});

// A sealed DM, opened on this device if it has unlocked its key; otherwise "New message".
async function openedBody(d) {
  if (!d.sealed || !d.lock || !self.E2E) return d.body || '';
  try {
    const mine = await self.E2E.loadDeviceKey();
    if (!mine) return d.body || '';
    const p = await self.E2E.open(await self.E2E.openLocked(d.lock, mine.privateKey), d.sealed);
    const a = (p.a || [])[0];
    return (p.t || '').slice(0, 180) || (a ? (a.kind === 'photo' ? '🖼 Photo' : a.kind === 'animation' ? 'GIF' : '📎 ' + (a.name || 'File')) : d.body || '');
  } catch (err) {
    return d.body || '';
  }
}

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data && e.data.text() }; }
  const shown = openedBody(d).then((body) => self.registration.showNotification(d.title || <?= json_encode(config('group_name'), JSON_UNESCAPED_UNICODE) ?>, {
    body,
    tag: d.tag || undefined,          // one notification per topic, updated rather than stacked
    renotify: !!d.tag,
    icon: d.icon || undefined,
    badge: d.badge || undefined,
    data: { url: d.url || <?= json_encode(config('base_url')) ?> },
  }));
  const badge = typeof d.unread === 'number' && self.navigator.setAppBadge
    ? (d.unread ? self.navigator.setAppBadge(d.unread) : self.navigator.clearAppBadge()) : Promise.resolve();
  e.waitUntil(Promise.all([shown, badge.catch(() => {})]));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL(e.notification.data.url, self.location.origin).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (list) => {
    const open = list.find((c) => c.url.startsWith(self.location.origin + <?= json_encode(config('base_url')) ?>));
    if (open) {
      // The app is running: bring it forward and tell it where to go (more reliable
      // on iPhone than reloading it at the new address).
      await open.focus();
      open.postMessage({ type: 'open', url });
      return;
    }
    return self.clients.openWindow(url);
  }));
});
