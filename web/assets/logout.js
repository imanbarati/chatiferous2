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
    ];
    try {
      Object.keys(localStorage).filter((k) => k.startsWith('draft:')).forEach((k) => localStorage.removeItem(k));
      sessionStorage.clear();
    } catch (err) { /* storage unavailable */ }
    // Never let a slow step keep someone from logging out.
    Promise.race([Promise.all(steps), new Promise((r) => setTimeout(r, 1500))]).then(() => {
      form.dataset.cleared = '1';
      form.submit();
    });
  });
})();
