// The My marks screen: only the import form needs any script (the strict CSP rules out inline
// handlers, and the page is otherwise plain HTML).
(() => {
  'use strict';
  const form = document.querySelector('.b-import');
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.b-import-open')) return;
    form.hidden = false;
    document.querySelector('details.menu[open]')?.removeAttribute('open');
    form.querySelector('input[type="file"]')?.click();
  });
})();
