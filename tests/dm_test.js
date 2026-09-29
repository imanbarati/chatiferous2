// End-to-end encrypted DM test against the live site, with two temporary members (never real
// accounts: setting up keys on a real account would lock it to the test's password).
// Run with tests/run_dm_test.sh, which makes and removes the members.
const puppeteer = require('puppeteer-core');
const { execSync } = require('child_process');
const [,, sidA, sidB, idA, idB, pw] = process.argv;
const BASE = process.env.SITE_URL;   // from site.env, via the run_*.sh script
const SITE = new URL(BASE);
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log((ok ? 'ok   ' : 'FAIL ') + name + (ok || !detail ? '' : '  (' + detail + ')')); };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const SSH = process.env.SSH_CMD, PHP = process.env.PHP, APPDIR = process.env.REMOTE_APP;
const sql = (php) => execSync(`${SSH} "cd ~/${APPDIR} && ${PHP} -r 'require \\"lib/bootstrap.php\\"; ${php}'"`).toString();

(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.CHROMIUM || '/usr/bin/chromium-browser', headless: 'new', args: ['--no-sandbox'] });
  const person = async (sid, phone) => {
    const ctx = await browser.createBrowserContext();   // separate cookies and storage per person
    const p = await ctx.newPage();
    if (phone) {
      await p.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
      await p.evaluateOnNewDocument(() => { try { delete window.PasswordCredential; } catch (e) { /* like an iPhone: no direct saving */ } });
    }
    else await p.setViewport({ width: 1200, height: 850 });
    p.on('pageerror', (e) => console.log('  page error:', e.message));
    p.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') console.log('  console:', m.text()); });
    p.on('dialog', (d) => d.accept());
    await p.setCookie({ name: process.env.COOKIE, value: sid, domain: SITE.hostname, path: SITE.pathname, secure: true, httpOnly: true });
    return p;
  };
  const setUp = async (p) => {   // the "Private messages" set-up and the recovery code
    await p.waitForSelector('.k-setup .k-in', { timeout: 15000 });
    await p.type('.k-setup .k-in', pw);
    await p.click('.k-setup button.primary');
    await p.waitForSelector('.recovery-code', { timeout: 20000 });
    const code = await p.$eval('.recovery-code', (e) => e.textContent.trim());
    if (process.env.SHOTS) await p.screenshot({ path: process.env.SHOTS + '/recovery-code-' + (p.viewport().width < 500 ? 'phone' : 'desktop') + '.png' });
    await p.click('.rc-copy');
    await wait(300);
    const copied = await p.$eval('.rc-copy', (e) => e.textContent);
    check('recovery code: Copy button copies it (' + (p.viewport().width < 500 ? 'iPhone-style' : 'desktop') + ')', copied.includes('Copied'), copied);
    await p.click('.rc-done');
    return code;
  };
  const lastText = (p) => p.evaluate(() => [...document.querySelectorAll('.messages .msg .text')].map((e) => e.innerText).join(' || '));
  try {
    const a = await person(sidA, false), b = await person(sidB, true);
    // A starts a conversation with B (B hasn't set up yet).
    await a.goto(BASE, { waitUntil: 'networkidle0' });
    await a.click('[data-folder="dms"]');
    await a.click('.new-dm');
    await a.waitForSelector('.newdm-q');
    await a.type('.newdm-q', 'Automated Test B');
    await wait(400);
    await a.click(`[data-user-pick="${idB}"]`);
    await wait(2500);
    check('A: note that B hasn’t set up yet', (await a.$eval('.dm-note', (e) => e.innerText).catch(() => '')).includes('hasn’t set up'));
    await a.type('.composer textarea', '[Automated test] the **secret** plan');
    await a.keyboard.press('Enter');
    const codeA = await setUp(a);
    check('A: recovery code shown once, well formed', /^([A-Z2-7]{4}-){7}[A-Z2-7]{4}$/.test(codeA));
    await wait(2500);
    check('A: own message shown opened, with formatting', (await a.$eval('.messages .msg.out .text', (e) => e.innerHTML).catch(() => '')).includes('<strong>secret</strong>'));
    const stored = sql(`echo q(\\"SELECT text FROM messages m JOIN topics t ON t.id = m.topic_id WHERE t.kind = \\\\\\"dm\\\\\\" AND m.user_id = ${idA} ORDER BY m.id DESC LIMIT 1\\")->fetchColumn();`);
    check('server: stored text is sealed, not readable', stored.startsWith('e2e1:') && !stored.includes('secret'), stored.slice(0, 40));
    // B arrives, sets up, and gets the conversation shared by A's app automatically.
    await b.goto(BASE, { waitUntil: 'networkidle0' });
    // Pull-to-refresh on the topic list (a finger dragged down from the top reloads the page).
    {
      const box = await (await b.$('#topics')).boundingBox();
      const x = box.x + box.width / 2, y = box.y + 20;
      const reloaded = b.waitForNavigation({ timeout: 10000 }).then(() => true).catch(() => false);
      await b.touchscreen.touchStart(x, y);
      for (let i = 1; i <= 10; i++) { await b.touchscreen.touchMove(x, y + i * 20); await wait(20); }
      await b.touchscreen.touchEnd();
      check('B: pulling the topic list down refreshes it', await reloaded);
      await wait(1500);
    }
    await b.tap('[data-folder="dms"]');
    await wait(800);
    await b.tap('#topics a.dm-row');
    await setUp(b);
    let opened = false;
    for (let i = 0; i < 20 && !opened; i++) { await wait(1500); opened = (await lastText(b)).includes('secret plan'); }
    check('B: after setting up, A’s app shared the key and B reads the message', opened, await lastText(b));
    // B replies with text and a photo.
    await b.type('.composer textarea', '[Automated test] reply from B');
    await b.tap('.composer .send');
    await wait(1500);
    const png = Buffer.from('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', 'base64');   // a real 64×48 PNG
    require('fs').writeFileSync(process.env.HOME + '/dm_test_photo.png', png);
    const [chooser] = await Promise.all([b.waitForFileChooser(), b.evaluate(() => document.querySelector('.photo-input').click())]);
    await chooser.accept([process.env.HOME + '/dm_test_photo.png']);
    await wait(2500);
    await b.tap('.composer .send');
    await wait(3000);
    const stat = sql(`\\$a = q(\\"SELECT a.path, a.mime FROM attachments a JOIN messages m ON m.id = a.message_id JOIN topics t ON t.id = m.topic_id WHERE t.kind = \\\\\\"dm\\\\\\" AND m.user_id = ${idB} ORDER BY a.id DESC LIMIT 1\\")->fetch(); echo \\$a[\\"mime\\"], \\" \\", bin2hex(substr(file_get_contents(config(\\"uploads_dir\\") . \\"/\\" . \\$a[\\"path\\"]), 0, 8));`);
    check('server: the photo is stored sealed (not a PNG)', stat.startsWith('application/octet-stream') && !stat.includes('89504e47'), stat);
    // A sees both, opened; ✓✓ appears on B's side once A has read.
    await a.bringToFront();
    let seen = false;
    for (let i = 0; i < 15 && !seen; i++) { await wait(1500); seen = (await lastText(a)).includes('reply from B'); }
    check('A: reads B’s reply', seen);
    let photo = false;
    for (let i = 0; i < 10 && !photo; i++) { await wait(1000); photo = await a.evaluate(() => !!document.querySelector('.messages .att-photo img[src^="blob:"]')); }
    check('A: the photo opens on the device (blob), same shape as sent', photo);
    await b.bringToFront();
    await wait(6000);
    check('B: ✓✓ once A has read', (await b.$$eval('.messages .msg.out .ticks', (t) => t.map((x) => x.textContent))).includes('✓✓'));
    // Search inside the DM, on the device.
    await a.bringToFront();
    await a.click('.topic-menu');
    await a.click('[data-dact="search"]');
    await a.type('.search-input', 'secret');
    await wait(3000);
    check('A: search in the DM finds it (on the device)', (await a.$$eval('.search-results .result', (r) => r.length).catch(() => 0)) === 1);
    await a.click('.search-close');
    // A starts fresh (lost password and code): B restores A with one tap.
    await a.evaluate(() => indexedDB.deleteDatabase('chatiferous-e2e'));
    await a.reload({ waitUntil: 'networkidle0' });
    await wait(1500);
    await a.waitForSelector('.k-fresh', { timeout: 15000 });
    await a.click('.k-fresh');
    await a.type('.k-freshform .k-in', pw);
    await a.click('.k-freshform button.primary');
    await a.waitForSelector('.rc-done', { timeout: 20000 });
    await a.click('.rc-done');
    await wait(2000);
    check('A (fresh keys): old messages locked, waiting', (await lastText(a)).includes('Waiting for access'));
    await b.bringToFront();
    let asked = false;
    for (let i = 0; i < 12 && !asked; i++) { await wait(1500); asked = !!(await b.$('.dm-restore')); }
    check('B: asked to restore A’s access', asked);
    if (asked) await b.tap('.dm-restore');
    await a.bringToFront();
    let back = false;
    for (let i = 0; i < 15 && !back; i++) { await wait(1500); back = (await lastText(a)).includes('secret plan'); }
    check('A: after B’s tap, the whole conversation reads again', back);

    // Logging out clears this device's key and drafts (the session file is removed by the runner).
    await a.evaluate(() => localStorage.setItem('draft:999999', 'unsent'));
    await a.evaluate(() => document.querySelector('details.menu').open = true);
    await Promise.all([a.waitForNavigation({ timeout: 15000 }), a.click('form[action$="logout.php"] button')]);
    check('A: logging out lands on the sign-in page', a.url().includes('login.php'));
    const left = await a.evaluate(async () => ({
      db: (await indexedDB.databases()).some((d) => d.name === 'chatiferous-e2e'),
      draft: localStorage.getItem('draft:999999'),
    }));
    check('A: logging out removed the device key and drafts', !left.db && left.draft === null, JSON.stringify(left));
  } catch (e) {
    check('crashed: ' + e.message, false);
  } finally {
    await browser.close();
    try { require('fs').unlinkSync(process.env.HOME + '/dm_test_photo.png'); } catch (e) { /* gone */ }
  }
  const failed = results.filter((r) => !r).length;
  console.log(`${results.length - failed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
