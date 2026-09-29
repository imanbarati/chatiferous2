// Takes a picture of every screen the app has, in both themes and at both widths, so they can be
// looked at rather than reasoned about. Read-only: it posts nothing and changes no setting.
//
//   tests/design_audit.sh [outdir]
const puppeteer = require('puppeteer-core');

const [,, sid, outdir] = process.argv;
const BASE = process.env.SITE_URL;
const SITE = new URL(BASE);
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

const VIEWS = [
  // name, url, what to do once it has loaded
  ['topic-list', '', null],
  ['topic-view', 't/5', null],
  ['messages', 'messages', null],
  ['reader-chapter', 'read/JHN/3', null],
  ['reader-psalm', 'read/PSA/23', null],
  ['marks', 'marks', null],
  ['reading', 'reading', null],
  ['account', 'account.php', null],
  ['invite', 'invite.php', null],
  ['notifications', 'notifications.php', null],
  ['chat-menu', '', async (p) => { await p.evaluate(() => document.querySelector('.pane-list .menu summary').click()); }],
  ['reader-menu', 'read/JHN/3', async (p) => { await p.evaluate(() => document.querySelector('.b-bar .menu summary').click()); }],
  ['book-picker', 'read/JHN/3', async (p) => { await p.evaluate(() => document.querySelector('.b-where').click()); }],
  ['version-picker', 'read/JHN/3', async (p) => { await p.evaluate(() => document.querySelector('.b-version').click()); }],
  ['reader-search', 'read/JHN/3', async (p) => {
    await p.evaluate(() => document.querySelector('.b-search').click());
    await wait(500);
    await p.evaluate(() => { const i = document.querySelector('.b-find input'); if (i) { i.value = 'shepherd'; i.dispatchEvent(new Event('input', { bubbles: true })); } });
    await wait(1200);
  }],
  ['verse-actions', 'read/JHN/3', async (p) => {
    await p.evaluate(() => document.querySelector('.v[data-v="16"]').dispatchEvent(new MouseEvent('contextmenu', { bubbles: true })));
  }],
  ['note-sheet', 'read/2SA/3?v=KJV', async (p) => {
    await p.evaluate(() => (document.querySelector('.b-chapter .fn') || document.querySelector('.b-dot'))?.click());
  }],
  ['commentary', 'read/JHN/3', async (p) => {
    await p.evaluate(() => document.querySelector('.vn[data-v="16"]').click());
    await wait(2500);
  }],
  ['commentary-choose', 'read/JHN/3', async (p) => {
    await p.evaluate(() => document.querySelector('.vn[data-v="16"]').click());
    await wait(2500);
    await p.evaluate(() => document.querySelector('[data-comm="choose"]')?.click());
  }],
  ['commentary-look', 'read/JHN/3', async (p) => {
    await p.evaluate(() => document.querySelector('.vn[data-v="16"]').click());
    await wait(2500);
    await p.evaluate(() => document.querySelector('[data-comm="look"]')?.click());
  }],
  ['chat-search', 't/5', async (p) => {
    await p.evaluate(() => document.querySelector('.search-open')?.click());
    await wait(600);
  }],
  ['message-menu', 't/5', async (p) => {
    const box = await p.evaluate(() => {
      const m = [...document.querySelectorAll('.msg .bubble')].pop();
      m.scrollIntoView({ block: 'center' });
      const r = m.getBoundingClientRect();
      return { x: r.left + 20, y: r.top + 12 };
    });
    await p.mouse.click(box.x, box.y, { button: 'right' });
  }],
];

const launch = () => puppeteer.launch({
  executablePath: process.env.CHROMIUM || '/usr/bin/chromium-browser',
  headless: 'new', args: ['--no-sandbox', '--disable-dev-shm-usage'], protocolTimeout: 30000,
});

(async () => {
  const errors = [];
  for (const theme of ['light', 'dark']) {
    for (const [label, width, height, mobile] of [['phone', 390, 844, true], ['desk', 1280, 860, false]]) {
      // A browser now and then stops answering; rather than lose the run, start another.
      let browser = await launch();
      for (const [name, url, act] of VIEWS) {
        let p;
        try {
          p = await browser.newPage();
        } catch (e) {
          try { await browser.close(); } catch (e2) { /* already gone */ }
          browser = await launch();
          p = await browser.newPage();
        }
        p.on('pageerror', (e) => errors.push(`${name} ${theme} ${label}: ${e.message}`));
        p.on('dialog', (d) => d.dismiss());
        if (theme === 'dark') await p.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
        await p.setViewport({ width, height, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 2 });
        await p.setCookie({ name: process.env.COOKIE, value: sid, domain: SITE.hostname, path: SITE.pathname, secure: true, httpOnly: true });
        try {
          await p.goto(BASE + url, { waitUntil: 'domcontentloaded' });
          await wait(2600);
          if (act) { await act(p); await wait(900); }
          await p.screenshot({ path: `${outdir}/${theme}-${label}-${name}.png` });
        } catch (e) {
          errors.push(`${name} ${theme} ${label}: ${e.message.split('\n')[0]}`);
        }
        try { await p.close(); } catch (e) { /* the browser went away */ }
      }
      try { await browser.close(); } catch (e) { /* already gone */ }
    }
  }
  console.log(errors.length ? 'page errors:\n  ' + errors.join('\n  ') : 'no page errors');
})();
