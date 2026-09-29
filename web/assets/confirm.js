// Buttons with data-confirm ask before submitting (inline handlers are blocked by our CSP).
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-confirm]');
  if (btn && !confirm(btn.dataset.confirm)) e.preventDefault();
});

// Buttons with data-copy put that text on the clipboard.
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy]');
  if (!btn) return;
  e.preventDefault();
  try {
    await navigator.clipboard.writeText(btn.dataset.copy);
    const old = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(() => { btn.textContent = old; }, 1500);
  } catch (err) {
    prompt('Copy this link:', btn.dataset.copy);
  }
});
