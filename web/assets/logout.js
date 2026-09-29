// Logging out clears this device's private data, as Telegram does: the direct-message key,
// unsent drafts, and this device's notifications. The server side (the session) ends on submit.
(() => {
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!form.matches('form[action$="logout.php"]') || form.dataset.cleared) return;
    e.preventDefault();
    const steps = [
      new Promise((done) => {
        try {
          const r = indexedDB.deleteDatabase('chatiferous-e2e');
          r.onsuccess = r.onerror = r.onblocked = () => done();
        } catch (err) { done(); }
      }),
      navigator.serviceWorker?.getRegistration()
        .then((reg) => reg?.pushManager.getSubscription())
        .then((sub) => sub?.unsubscribe()).catch(() => {}),
      // The copy of the conversation this device kept for reading with no signal, and the pages
      // cached along the way — both hold other members' messages, so neither may wait here for
      // whoever signs in next. The downloaded Bibles stay: they are nobody's private business.
      //
      // Emptied rather than deleted: deleting a database is refused while anything still has it
      // open, and the app may well be part way through keeping a copy as someone logs out.
      // Clearing its contents is not refused, and leaves nothing behind either way.
      new Promise((done) => {
        try {
          const r = indexedDB.open('chatiferous-local', 1);
          r.onupgradeneeded = () => {
            if (!r.result.objectStoreNames.contains('keep')) r.result.createObjectStore('keep');
          };
          r.onsuccess = () => {
            const db = r.result;
            try {
              const tx = db.transaction('keep', 'readwrite');
              tx.objectStore('keep').clear();
              tx.oncomplete = tx.onerror = tx.onabort = () => { db.close(); done(); };
            } catch (err) { db.close(); done(); }
          };
          r.onerror = r.onblocked = () => done();
        } catch (err) { done(); }
      }),
      (self.caches ? caches.keys()
        .then((names) => Promise.all(names.filter((n) => n.startsWith('chatiferous-')).map((n) => caches.delete(n))))
        .catch(() => {}) : Promise.resolve()),
    ];
    try {
      Object.keys(localStorage).filter((k) => k.startsWith('draft:')).forEach((k) => localStorage.removeItem(k));
      sessionStorage.clear();
    } catch (err) { /* storage unavailable */ }
    // Never let a slow step keep someone from logging out.
    Promise.race([Promise.all(steps), new Promise((r) => setTimeout(r, 3000))]).then(() => {
      form.dataset.cleared = '1';
      form.submit();
    });
  });
})();
