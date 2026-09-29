// Sign-in and password pages: hands the password to the app for one moment, so it can unlock
// (or set up) end-to-end encrypted messages without asking again. It lives only in this tab's
// session storage and the app removes it as soon as it has used it. Nothing is sent anywhere.
(() => {
  try { sessionStorage.removeItem('pw-once'); sessionStorage.removeItem('pw-old'); } catch (e) { /* storage unavailable */ }
  document.addEventListener('submit', (e) => {
    const pw = e.target.querySelector('input[type="password"][name="password"]');
    const old = e.target.querySelector('input[type="password"][name="current"]');   // changing your password
    try {
      if (pw && pw.value) sessionStorage.setItem('pw-once', pw.value);
      if (old && old.value) sessionStorage.setItem('pw-old', old.value);
    } catch (err) { /* ignore */ }
  }, true);
})();
