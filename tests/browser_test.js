// End-to-end test in a real browser against the live site, as the owner.
// Posts clearly-marked test messages in the "Platform" topic and deletes them
// afterwards. Run with tests/run_browser_tests.sh (it creates and removes the session).
const puppeteer = require('puppeteer-core');

const [,, sid, topic, shots] = process.argv;
const BASE = process.env.SITE_URL;   // from site.env, via the run_*.sh script
const SITE = new URL(BASE);
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let passed = 0;
const failed = [];
const check = (name, ok, detail = '') => { if (ok) passed++; else failed.push(name + (detail ? ` (${detail})` : '')); };

(async () => {
  const browser = await puppeteer.launch({
    executablePath: process.env.CHROMIUM || '/usr/bin/chromium-browser',
    headless: 'new', args: ['--no-sandbox'], protocolTimeout: 20000,
  });
  const errors = [];
  const open = async (width, height, mobile = false) => {
    const p = await browser.newPage();
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('dialog', (d) => d.accept());
    await p.setViewport({ width, height, isMobile: mobile, hasTouch: mobile });
    await p.setCookie({ name: process.env.COOKIE, value: sid, domain: SITE.hostname, path: SITE.pathname, secure: true, httpOnly: true });
    await p.goto(BASE + 't/' + topic, { waitUntil: 'networkidle0' });
    return p;
  };
  const a = await open(1280, 860);
  let b = await open(1280, 860);          // a second window, watching for live updates
  await a.bringToFront();                 // background tabs can't be driven reliably
  const ids = (p) => p.$$eval('.messages [data-id]', (els) => els.map((e) => +e.dataset.id));
  const before = new Set(await ids(a));
  const newIds = async () => (await ids(a)).filter((i) => !before.has(i));
  const last = async () => (await newIds()).pop();
  // Waits (up to 6 s) for a condition in the page instead of a fixed pause: the shared server's speed varies.
  const until = (p, fn, arg) => p.waitForFunction(fn, { timeout: 6000 }, arg).then(() => true, () => false);

  try {
    // Formatting
    await a.type('.composer textarea', '[Automated test, please ignore] **bold** __italic__ `code` #tag https://example.com');
    await a.keyboard.press('Enter');
    await wait(1500);
    const first = await last();
    const html = await a.$eval(`.msg[data-id="${first}"] .text`, (e) => e.innerHTML);
    check('formatting renders', html.includes('<strong>bold</strong>') && html.includes('<em>italic</em>')
      && html.includes('<code>code</code>') && html.includes('class="hashtag"') && html.includes('href="https://example.com"'));

    // Live update in the other window (the wait also lets the link preview load)
    await wait(4000);
    check('link preview card appears', await until(a, (id) => document.querySelector(`.msg[data-id="${id}"] .lp-title`)?.textContent === 'Example Domain', first));
    check('live update reaches another window', !!(await b.$(`.msg[data-id="${first}"]`)));
    await b.close();                       // an idle background window makes headless Chromium stall

    // Reaction from the right-click menu
    const bb = await (await a.$(`.msg[data-id="${first}"] .bubble`)).boundingBox();
    await a.mouse.click(bb.x + 30, bb.y + 15, { button: 'right' });
    await wait(300);
    check('menu opens', !!(await a.$('.ctx')));
    if (shots) await a.screenshot({ path: shots + '/menu.png' });
    await a.click('.menu-reactions [data-emoji="👍"]');
    await wait(1200);
    check('reaction shows', (await a.$eval(`.msg[data-id="${first}"]`, (e) => e.querySelector('.rx.mine')?.textContent || '')).includes('👍'));

    // Reply by double-click (re-measure: the reaction made the bubble taller)
    const tb = await (await a.$(`.msg[data-id="${first}"] .text`)).boundingBox();
    await a.mouse.click(tb.x + 20, tb.y + 8, { clickCount: 2 });
    await wait(300);
    check('reply bar opens', await a.$eval('.compose-bar', (e) => !e.hidden));
    await a.type('.composer textarea', '[Automated test] reply (@Chu');
    await wait(1500);
    check('@ list appears after "("', await a.$eval('.mention-list', (e) => !e.hidden));
    await a.$eval('.composer textarea', (e) => { e.value = '[Automated test] reply'; });
    await a.keyboard.press('Escape');
    await a.focus('.composer textarea');
    await a.keyboard.press('Enter');
    await wait(1500);
    const reply = await last();
    check('reply links to original', !!(await a.$(`.msg[data-id="${reply}"] .reply[data-jump="${first}"]`)));

    // Up arrow edits the last message
    // (wait until the reply is really ours in the app and edit mode is showing, or " (edited)"
    // would go out as a new message)
    await until(a, (id) => !!document.querySelector(`.msg[data-id="${id}"].out`), reply);
    await a.focus('.composer textarea');
    let editing = false;
    for (let i = 0; i < 5 && !editing; i++) {
      await a.keyboard.press('ArrowUp');
      editing = await until(a, () => { const bar = document.querySelector('.compose-bar'); return bar && !bar.hidden; });
    }
    if (!editing) throw new Error('edit mode never opened');
    await a.keyboard.type(' (edited)');
    await a.keyboard.press('Enter');
    check('edit saves and shows "edited"', await until(a, (id) => document.querySelector(`.msg[data-id="${id}"] .text`)?.textContent.includes('(edited)edited'), reply));

    // Bible references in a message become links into the reader
    await a.type('.composer textarea', '[Automated test, please ignore] see John 3:16 and 1 Jn 2:1-3 (not 3:16 alone)');
    await a.keyboard.press('Enter');
    await wait(1500);
    const refs = await a.evaluate(() => [...document.querySelectorAll('.msg:last-child .bref')].map((x) => [x.textContent, x.getAttribute('href')]));
    check('Bible references become links', refs.length === 2
      && refs[0][1].includes('read/JHN/3') && refs[0][1].endsWith('#v16')
      && refs[1][1].includes('read/1JN/2'), JSON.stringify(refs));
    check('a bare chapter:verse is left alone', !refs.some((r) => r[0].trim() === '3:16'));

    // The Bible reader with no signal: a book downloaded to the device still reads.
    {
      const r = await browser.newPage();
      r.on('pageerror', (e) => errors.push(e.message));
      await r.setCookie({ name: process.env.COOKIE, value: sid, domain: SITE.hostname, path: SITE.pathname, secure: true, httpOnly: true });
      await r.goto(BASE + 'read/JHN/3', { waitUntil: 'networkidle0' });
      await r.evaluate(() => navigator.serviceWorker.ready);
      await r.reload({ waitUntil: 'networkidle0' });      // now the worker sees the page's own assets
      const version = await r.evaluate(() => JSON.parse(document.body.dataset.bible).version);
      const base = await r.evaluate(() => JSON.parse(document.body.dataset.bible).base);

      // Download one book the way the menu's switch does, and mark the version as kept here.
      const stored = await r.evaluate(async (v, base) => {
        const res = await fetch(`${base}api/bible.php?action=book&v=${v}&b=JUD`, { credentials: 'same-origin' });
        const bundle = await res.json();
        const d = await new Promise((ok, no) => {
          const q = indexedDB.open('bible-offline', 1);
          q.onsuccess = () => ok(q.result); q.onerror = () => no(q.error);
        });
        await new Promise((ok) => {
          const tx = d.transaction(['chapters', 'versions'], 'readwrite');
          for (const ch of bundle.chapters) {
            tx.objectStore('chapters').put({ html: ch.html, notes: ch.notes, xrefs: ch.xrefs, name: bundle.name, biblehub: bundle.biblehub },
              `${v}:JUD:${ch.chapter}`);
          }
          tx.objectStore('versions').put({ version: v, at: Date.now() }, v);
          tx.oncomplete = ok;
        });
        return bundle.chapters.length;
      }, version, base);
      check('a book downloads in one request', stored === 1, 'chapters: ' + stored);

      // A verse number opens the commentaries on that verse.
      await r.goto(BASE + 'read/ROM/11', { waitUntil: 'domcontentloaded' });
      await until(r, () => !!document.querySelector('.vn[data-v="33"]'));
      await r.evaluate(() => document.querySelector('.vn[data-v="33"]').click());
      const commentary = await until(r, () => !!document.querySelector('.b-comm-entry'));
      const names = await r.evaluate(() => [...document.querySelectorAll('.b-comm-who')]
        .map((h) => h.textContent));
      check('a verse number opens the commentaries', commentary && names.includes('Matthew Henry (Full)'),
        names.join(', '));
      // and the list of which to show opens from there (without changing what's chosen)
      await r.evaluate(() => document.querySelector('[data-comm="choose"]').click());
      const chooser = await until(r, () => document.querySelectorAll('.b-work').length > 20);
      check('the reader can choose which commentaries to show', chooser);
      await r.keyboard.press('Escape');
      await r.evaluate(() => document.querySelector('.b-sheet')?.remove());

      // Marking a verse: hold it (a right-click, on a desktop) and pick a colour.
      await r.goto(BASE + 'read/3JN/1', { waitUntil: 'domcontentloaded' });
      await until(r, () => !!document.querySelector('.v[data-v="2"]'));
      await r.evaluate(() => document.querySelector('.v[data-v="2"]')
        .dispatchEvent(new MouseEvent('contextmenu', { bubbles: true })));
      const sheet = await until(r, () => !!document.querySelector('.b-actions .b-sw-green'));
      await r.click('.b-actions .b-sw-green');
      check('a verse can be highlighted', sheet
        && await until(r, () => document.querySelector('.v[data-v="2"]')?.classList.contains('b-hl-green')));

      // and it's the account's, not the page's: it's still there after a reload
      await wait(1800);
      await r.goto(BASE + 'read/3JN/1', { waitUntil: 'domcontentloaded' });
      check('a highlight is kept in the account',
        await until(r, () => document.querySelector('.v[data-v="2"]')?.classList.contains('b-hl-green')));

      await r.goto(BASE + 'marks', { waitUntil: 'domcontentloaded' });
      await until(r, () => !!document.querySelector('.b-tabs'));
      const listed = await r.evaluate(() => [...document.querySelectorAll('.b-mark-ref')].map((a) => a.textContent.trim()));
      check('the mark is on the My marks screen', listed.includes('3 John 1:2'), listed.slice(0, 3).join(', '));
      const exported = await r.evaluate(async (base) => {
        const res = await fetch(base + 'marks?export=json', { credentials: 'same-origin' });
        return (await res.text()).includes('"3JN"');
      }, BASE);
      check('marks export as a file you can keep', exported);

      // Tidy up after ourselves: the test's mark comes off again.
      await r.goto(BASE + 'read/3JN/1', { waitUntil: 'domcontentloaded' });
      await r.evaluate(() => document.querySelector('.v[data-v="2"]')
        .dispatchEvent(new MouseEvent('contextmenu', { bubbles: true })));
      await until(r, () => !!document.querySelector('.b-actions .b-sw-off'));
      await r.click('.b-actions .b-sw-off');
      await wait(1800);      // the account is told after the page is marked; let that land
      await r.goto(BASE + 'read/3JN/1', { waitUntil: 'domcontentloaded' });
      check('a highlight comes off again',
        await until(r, () => document.querySelector('.v[data-v="2"]') && !document.querySelector('.b-hl')));

      await r.setOfflineMode(true);
      await r.goto(BASE + 'read/JUD/1?v=' + version, { waitUntil: 'domcontentloaded' }).catch(() => {});
      const shown = await until(r, () => document.querySelector('.b-chapter')?.dataset.book === 'JUD'
        && document.querySelector('.b-title')?.textContent.startsWith('Jude'));
      const text = await r.evaluate(() => document.querySelector('.b-chapter')?.textContent || '');
      check('the reader opens with no signal', shown, text.slice(0, 60));
      check('the downloaded chapter is really there', /servants? of Jesus Christ|Jude, (a|the) servant/i.test(text), text.slice(0, 80));
      check('the chapter after it is worked out offline', await r.evaluate(() => !!document.querySelector('.b-prev')));
      await r.setOfflineMode(false);
      await r.close();
    }


    // A full-size 12-megapixel photo, made in the browser
    await a.evaluate(async () => {
      const c = document.createElement('canvas');
      c.width = 4032; c.height = 3024;
      const g = c.getContext('2d');
      for (let y = 0; y < 3024; y += 16) { g.fillStyle = `hsl(${y % 360},60%,50%)`; g.fillRect(0, y, 4032, 16); }
      const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
      const dt = new DataTransfer();
      dt.items.add(new File([blob], 'IMG_0001.jpg', { type: 'image/jpeg' }));
      const input = document.querySelector('.photo-input');
      input.files = dt.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await wait(6000);
    check('12 MP photo uploads', await a.$eval('.att-strip', (e) => !!e.querySelector('.up.done')),
      await a.$eval('.att-strip', (e) => e.textContent.trim()));
    await a.type('.composer textarea', '[Automated test] photo');
    await a.keyboard.press('Enter');
    await wait(2500);
    const photo = await last();
    await a.waitForFunction((id) => document.querySelector(`.msg[data-id="${id}"] .att-photo img`)?.complete, { timeout: 15000 }, photo).catch(() => {});
    const dims = await a.$eval(`.msg[data-id="${photo}"] .att-photo img`, (e) => e.naturalWidth + 'x' + e.naturalHeight).catch(() => 'none');
    check('photo shown, shrunk to 2560 px', dims === '2560x1920', dims);

    // Poll: create and vote
    await a.click('.composer .attach');
    await wait(300);
    await a.click('[data-att="poll"]');
    await wait(300);
    await a.type('.poll-form input[name=q]', '[Automated test] poll');
    const opts = await a.$$('.poll-form input[name=o]');
    await opts[0].type('Yes');
    await opts[1].type('No');
    await a.click('.poll-form .primary');
    await wait(2000);
    const poll = await last();
    await a.click(`.msg[data-id="${poll}"] .opt.votable`);
    await wait(1500);
    check('poll vote shows results', (await a.$eval(`.msg[data-id="${poll}"] .poll`, (e) => e.textContent)).includes('100%'));
    await a.click(`.msg[data-id="${poll}"] .poll-change`);
    check('poll: change vote reopens the choices', await until(a, (id) => !!document.querySelector(`.msg[data-id="${id}"] .opt.votable`), poll));

    // Quote-reply: select a word, right-click it, Quote
    const selectBold = () => a.$eval(`.msg[data-id="${first}"] .text strong`, (e) => {
      e.scrollIntoView({ block: 'center' });
      const r = document.createRange(); r.selectNodeContents(e);
      const s = getSelection(); s.removeAllRanges(); s.addRange(r);
      const bb = r.getBoundingClientRect();
      return { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 };
    });
    await selectBold();
    await wait(400);
    const qp = await selectBold();
    await a.mouse.click(qp.x, qp.y, { button: 'right' });
    await wait(300);
    if (!(await a.$('.ctx [data-act="quote"]'))) console.log('quote menu was:', await a.$eval('.ctx', (e) => e.innerText).catch(() => 'no menu'));
    await a.click('.ctx [data-act="quote"]');
    check('quote: bar shows the selected words', await a.$eval('.compose-bar', (e) => !e.hidden && e.textContent.includes('Quote from') && e.textContent.includes('bold')));
    await a.type('.composer textarea', '[Automated test, please ignore] quote');
    await a.keyboard.press('Enter');
    check('quote: reply header shows just the quoted words', await until(a, () => {
      const q = [...document.querySelectorAll('.messages .reply-quote')].pop();
      return q && q.textContent === 'bold';
    }));

    // ~ links a topic. (It links to this same topic: following a link elsewhere would change
    // which messages the cleanup below sees.)
    await a.type('.composer textarea', '[Automated test, please ignore] see ~Platfor');
    check('~ offers matching topics', await until(a, () => [...document.querySelectorAll('.mention-list [data-topic-pick]')].some((x) => x.textContent.includes('Platform'))));
    await a.keyboard.press('Enter');
    await a.keyboard.press('Enter');
    check('~ topic becomes a link in the sent message', await until(a, (t) => {
      const l = [...document.querySelectorAll('.messages .text a')].pop();
      return l && l.textContent === 'Platform' && l.pathname.endsWith('/t/' + t);
    }, topic));
    const pagesBefore = (await a.browser().pages()).length;
    await a.$$eval('.messages .text a', (ls) => ls.pop().click());
    await wait(1500);
    check('topic links open in the app, not a new tab', (await a.browser().pages()).length === pagesBefore && (await a.evaluate(() => location.pathname)).endsWith('/t/' + topic));

    // Forward (into this same topic, so cleanup removes it), with a comment sent first
    await a.$eval(`.msg[data-id="${first}"] .bubble`, (e) => e.scrollIntoView({ block: 'center' }));
    await wait(300);
    const fb = await (await a.$(`.msg[data-id="${first}"] .bubble`)).boundingBox();
    await a.mouse.click(fb.x + 30, fb.y + 15, { button: 'right' });
    await wait(300);
    await a.click('.ctx [data-act="forward"]');
    await wait(500);
    check('forward: topic picker opens', !!(await a.$('.fwd-target')));
    await a.click(`.fwd-target[data-id="${topic}"]`);
    await wait(2500);
    check('forward: bar shows', await a.$eval('.compose-bar', (e) => !e.hidden && e.textContent.includes('Forward')));
    await a.type('.composer textarea', '[Automated test, please ignore] forward comment');
    await a.keyboard.press('Enter');
    await wait(2000);
    const fwdId = await last();
    check('forward: arrives with "Forwarded from" linking to the original',
      await a.$eval(`.msg[data-id="${fwdId}"]`, (e, id) => e.querySelector('.fwd-link')?.dataset.fwdId === String(id), first));

    // Posting from further up, then deleting that post, returns you to where you were reading;
    // and no message changes height when the list is redrawn (the "jiggle").
    await a.$eval('.scroller', (sc) => { sc.scrollTop = sc.scrollHeight - sc.clientHeight * 3; });
    await wait(600);
    const spot = () => a.evaluate(() => {
      const sc = document.querySelector('.scroller'), top = sc.getBoundingClientRect().top;
      const el = [...document.querySelectorAll('.messages [data-id]')].find((x) => x.getBoundingClientRect().bottom > top);
      return el.dataset.id + '@' + Math.round(el.getBoundingClientRect().top - top);
    });
    const spotBefore = await spot();
    await a.evaluate(() => {
      window.__grew = [];
      const list = document.querySelector('.messages');
      new MutationObserver(() => {
        const h = () => Object.fromEntries([...list.querySelectorAll('[data-id]')].map((e) => [e.dataset.id, e.offsetHeight]));
        const x = h();
        setTimeout(() => { const y = h(); for (const k in x) if (y[k] !== undefined && y[k] !== x[k]) window.__grew.push(k); }, 600);
      }).observe(list, { childList: true });
    });
    await a.type('.composer textarea', '[Automated test, please ignore] return point');
    await a.keyboard.press('Enter');
    await wait(2000);
    const rp = await last();
    await a.$eval(`.msg[data-id="${rp}"] .bubble`, (e) => e.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 100, clientY: 100 })));
    await wait(300);
    await a.click('.ctx [data-act="delete"]');
    await wait(2000);
    check('deleted message dissolves away', !(await a.$(`.msg[data-id="${rp}"]`)));
    check('post then delete returns to where you were reading', (await spot()) === spotBefore, spotBefore + ' vs ' + (await spot()));
    check('no message changes height after a redraw', (await a.evaluate(() => window.__grew)).length === 0, JSON.stringify(await a.evaluate(() => window.__grew)));

    // Phone layout: tapping a message opens the menu as a bottom sheet
    const phone = await open(390, 844, true);
    await phone.$eval(`.msg[data-id="${first}"] .bubble`, (e) => e.scrollIntoView({ block: 'center' }));
    await wait(300);
    const pb = await (await phone.$(`.msg[data-id="${first}"] .bubble`)).boundingBox();
    await phone.touchscreen.tap(pb.x + 30, pb.y + 15);
    await wait(400);
    check('phone: tap opens menu', !!(await phone.$('.ctx')));
    if (shots) await phone.screenshot({ path: shots + '/phone-menu.png' });
    await phone.close();
    await a.bringToFront();
  } catch (e) {
    failed.push('crashed: ' + e.message + (e.stack ? '\n    ' + e.stack.split('\n').slice(1, 3).join('\n    ') : ''));
  } finally {
    // Clean up everything this test posted.
    const mine = await newIds();
    for (const id of mine) {
      await a.evaluate(async (id) => {
        const app = JSON.parse(document.body.dataset.app);
        await fetch(app.base + 'api/delete.php', { method: 'POST', headers: { 'X-CSRF': app.csrf }, body: new URLSearchParams({ id }) });
      }, id);
    }
    b = await open(1280, 860);
    await wait(1000);
    const left = (await ids(b)).filter((i) => mine.includes(i));
    check('deletes reach the other window', left.length === 0, JSON.stringify(left));
    check('no page errors', errors.length === 0, errors.join('; '));
    await browser.close();
    console.log(`${passed} passed, ${failed.length} failed`);
    failed.forEach((f) => console.log('  FAILED: ' + f));
    process.exit(failed.length ? 1 : 0);
  }
})();
