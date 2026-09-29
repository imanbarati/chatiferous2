<?php
// The service worker (served by PHP so updates aren't held back by the host's proxy cache).
// It shows push notifications and opens the right topic when one is tapped.
require __DIR__ . '/boot.php';
header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('Service-Worker-Allowed: ' . config('base_url'));
?>
// Service worker.
// Direct messages arrive sealed; this device's key (kept by the app in IndexedDB) opens them.
importScripts('a.php?f=e2e.js&v=<?= (int)filemtime(__DIR__ . '/assets/e2e.js') ?>');
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

// Pages load from the network as usual. Two things are kept for when there's no signal: the
// reader's own page (so a downloaded Bible still opens) and the scripts and styles it needs.
const SHELL = 'chatiferous-shell';

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  const reader = url.pathname.includes('/read/') || url.pathname.endsWith('/bible.php');

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
  if (e.request.mode !== 'navigate') return;

  e.respondWith((async () => {
    try {
      const res = await fetch(e.request);
      if (reader && res.ok) {
        const c = await caches.open(SHELL);
        c.put('reader-shell', res.clone());      // the last reader page, as a shell to fall back on
      }
      return res;
    } catch (err) {
      if (reader) {
        const shell = await caches.match('reader-shell');
        if (shell) return shell;                 // the chapter itself comes from this device's copy
      }
      return new Response(
        '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>'
        + '<body style="font:18px system-ui;text-align:center;padding:4em 1em;color:#555">You’re offline.<br>Please check your connection and try again.</body>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
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
