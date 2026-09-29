// The chat with and without a signal.
//
// Two halves. The first guards what already works, so that building the offline side cannot
// quietly break reading the chat online — which is the thing everyone actually does. The second
// describes the offline side, and fails until it exists.
//
// It reads and never writes: no message is posted, nothing is deleted. It signs in as the
// throwaway reader account, so nobody's own reading or drafts are touched.
//
//   tests/run_offline_tests.sh
const puppeteer = require('puppeteer-core');

const [,, sid] = process.argv;
const BASE = process.env.SITE_URL;
const SITE = new URL(BASE);
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

let passed = 0;
const failed = [];
function check(name, ok, detail = '') {
  if (ok) {
    passed++;
    console.log('ok   ' + name);
  } else {
    failed.push(name + (detail ? ' (' + detail + ')' : ''));
    console.log('FAIL ' + name + (detail ? ' (' + detail + ')' : ''));
  }
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: process.env.CHROMIUM || '/usr/bin/chromium-browser',
    headless: 'new', args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const errors = [];
  const p = await browser.newPage();
  p.setDefaultNavigationTimeout(90000);
  p.on('pageerror', (e) => errors.push(e.message));
  p.on('dialog', (d) => d.dismiss());
  await p.setViewport({ width: 1100, height: 900 });
  await p.setCookie({ name: process.env.COOKIE, value: sid, domain: SITE.hostname, path: SITE.pathname, secure: true, httpOnly: true });

  const until = (fn, arg, ms = 8000) => p.waitForFunction(fn, { timeout: ms }, arg).then(() => true, () => false);
  const msgIds = () => p.$$eval('.messages [data-id]', (els) => els.map((e) => +e.dataset.id));

  try {
    // ---------- what already works ----------
    await p.goto(BASE, { waitUntil: 'domcontentloaded' });
    check('the topic list arrives', await until(() => document.querySelectorAll('.topic').length > 1));

    // The first row of the list is Messages, which is the direct-message list rather than a topic.
    const topics = await p.$$eval('.topic', (els) => els.map((e) => e.getAttribute('href')))
      .then((hs) => hs.filter((h) => h && /\/t\/\d+$/.test(h)));
    // Which topics are busy, and which can be posted in, changes with the day, so their order in
    // the list is no guide. Probe each in turn and keep what it actually showed — no topic is
    // opened twice, which is what made this flap on a loaded host.
    const seen = [];
    const probed = [];
    for (const href of topics) {
      if (seen.length === 2) break;
      probed.push(href);
      await p.goto(new URL(href, BASE).href, { waitUntil: 'domcontentloaded' });
      if (!await until(() => document.querySelectorAll('.messages [data-id]').length > 0, null, 12000)) continue;
      const about = await p.evaluate(() => {
        const s = document.querySelector('.scroller'), c = document.querySelector('.composer');
        return {
          ids: [...document.querySelectorAll('.messages [data-id]')].map((e) => +e.dataset.id),
          deep: !!s && s.scrollHeight - s.clientHeight > 300,
          canPost: !!c && !c.hidden && !!c.querySelector('textarea'),
        };
      });
      // The first topic carries the draft and the scroll-place checks, so it has to take a draft
      // and be long enough to scroll in.
      if (!seen.length && !(about.canPost && about.deep)) continue;
      seen.push({ href, ...about });
    }
    const [first, second] = seen;
    check('there are two topics to move between', !!first && !!second, seen.length + ' of ' + topics.length + ' usable');
    if (!first || !second) throw new Error('no usable pair of topics; everything after this would be noise');

    check('opening a topic shows its messages', first.ids.length > 0);
    check('the messages run oldest at top, newest at bottom',
      first.ids.length > 1 && first.ids.every((v, i, a) => !i || a[i - 1] < v), JSON.stringify(first.ids.slice(0, 3)));
    check('another topic shows its own messages',
      second.ids.length > 0 && second.ids[0] !== first.ids[0], second.ids.length + ' messages');

    // Where you land on opening a topic. The ladder is: the message you asked for, else the place
    // you last were, else the first unread message, else the bottom — never the top. This is the
    // part worth guarding closely: restoring a conversation from a local copy changes what is on
    // screen at the moment the place is restored, and landing in the wrong spot is the sort of
    // regression nobody reports and everybody feels.
    await p.goto(new URL(first.href, BASE).href, { waitUntil: 'domcontentloaded' });
    await until(() => document.querySelectorAll('.messages [data-id]').length > 0);
    const topMessage = () => p.evaluate(() => {
      const s = document.querySelector('.scroller'), top = s.getBoundingClientRect().top;
      for (const el of document.querySelectorAll('.messages [data-id]')) {
        if (el.getBoundingClientRect().bottom > top + 4) {
          return { id: +el.dataset.id, atBottom: s.scrollHeight - s.scrollTop - s.clientHeight < 60 };
        }
      }
      return null;
    });
    // The place is restored once the messages are drawn, and link previews can shift things again
    // afterwards, so wait for the top of the view to stop moving rather than guess at a delay.
    // Guessing is what made this look like an app fault when it was only impatience.
    const restedTop = async (ms = 10000) => {
      const end = Date.now() + ms;
      let last = null, same = 0;
      while (Date.now() < end) {
        const now = await topMessage().catch(() => null);
        if (now && last && now.id === last.id && ++same >= 2) return now;
        if (!now || !last || now.id !== last.id) same = 0;
        last = now;
        await wait(350);
      }
      return last;
    };
    const parked = await p.evaluate(async () => {
      const s = document.querySelector('.scroller');
      s.scrollTop = Math.round((s.scrollHeight - s.clientHeight) / 2);
      s.dispatchEvent(new Event('scroll'));
      await new Promise((r) => setTimeout(r, 900));        // the place is written after a pause
      // This topic's key, not whichever one happens to be first: probing left places behind in
      // the other topics, and the wrong one looks like a wrong answer.
      const key = 'pos:' + location.pathname.replace(/\/$/, '').split('/').pop();
      const top = s.getBoundingClientRect().top;
      let atTop = 0;
      for (const el of document.querySelectorAll('.messages [data-id]')) {
        if (el.getBoundingClientRect().bottom > top + 4) { atTop = +el.dataset.id; break; }
      }
      return { key, place: JSON.parse(localStorage.getItem(key) || 'null'), atTop };
    });
    check('scrolling back records the place',
      !!parked.place && parked.place.id === parked.atTop, JSON.stringify(parked));
    const parkedAt = { id: parked.atTop, atBottom: false };

    await p.reload({ waitUntil: 'domcontentloaded' });
    await until(() => document.querySelectorAll('.messages [data-id]').length > 0);
    const landed = await restedTop();
    // "Where you left off" means within a screen of the message you were on — not the same pixel.
    // Previews and pictures load late and nudge things about; a reader would call that the place.
    const onScreen = (id) => p.evaluate((want) => {
      const s = document.querySelector('.scroller');
      if (!s) return false;
      const r = s.getBoundingClientRect();
      const el = document.querySelector(`.messages [data-id="${want}"]`);
      if (!el) return false;
      const b = el.getBoundingClientRect();
      return b.bottom > r.top - r.height && b.top < r.bottom + r.height;
    }, id);
    check('coming back lands where you left off, not at the bottom',
      !!landed && !!parkedAt && await onScreen(parkedAt.id) && !landed.atBottom,
      JSON.stringify({ landed, wanted: parkedAt }));

    // With no place recorded, the ladder's other rungs: the first unread message if there is one,
    // else the bottom. Never simply the top of whatever happened to be loaded.
    // Cleared from the topic list, not from inside the topic: leaving a topic saves the place on
    // the way out, which would put back the very key we are trying to be rid of.
    await p.goto(BASE, { waitUntil: 'domcontentloaded' });
    await until(() => document.querySelectorAll('.topic').length > 1);
    await p.evaluate(() => Object.keys(localStorage)
      .filter((k) => k.startsWith('pos:')).forEach((k) => localStorage.removeItem(k)));
    await p.goto(new URL(first.href, BASE).href, { waitUntil: 'domcontentloaded' });
    await until(() => document.querySelectorAll('.messages [data-id]').length > 0);
    await restedTop();
    const noPlace = await p.evaluate(() => {
      const s = document.querySelector('.scroller');
      if (!s) return { none: true, chat: document.querySelector('#chat')?.innerHTML.length || 0 };
      const top = s.getBoundingClientRect().top;
      const els = [...document.querySelectorAll('.messages [data-id]')];
      const vis = els.find((el) => el.getBoundingClientRect().bottom > top + 4);
      let unread = 0;
      for (let n = document.querySelector('#unread-bar')?.nextElementSibling; n; n = n.nextElementSibling) {
        if (n.dataset?.id) { unread = +n.dataset.id; break; }
      }
      // Previews loading can shift the mark a little after the scroll, so what matters is that it
      // is on screen — not that it sits exactly on the top row.
      const bar = document.querySelector('#unread-bar');
      const b = bar && bar.getBoundingClientRect();
      return { atBottom: s.scrollHeight - s.scrollTop - s.clientHeight < 60,
        firstVis: vis ? +vis.dataset.id : 0, unread,
        markOnScreen: !!b && b.bottom > top && b.top < s.getBoundingClientRect().bottom };
    });
    // The claim is that you are not dropped among messages you have already read: you open at the
    // unread mark or past it, or at the bottom when there is nothing new.
    check('with no place recorded it opens at the unread mark, or the bottom',
      noPlace.atBottom || noPlace.markOnScreen || (noPlace.unread > 0 && noPlace.firstVis >= noPlace.unread),
      JSON.stringify(noPlace));

    // A draft is kept per topic on the device, and is expected to go on working offline. Typed the
    // instant the box appears, before the messages have arrived, because that is the moment the
    // box used to be emptied out from under you.
    await p.goto(new URL(first.href, BASE).href, { waitUntil: 'domcontentloaded' });
    await until(() => !!document.querySelector('.composer textarea'), null, 20000);
    await p.evaluate(() => {
      window.__wentDisabled = false;
      const ta = document.querySelector('.composer textarea');
      new MutationObserver(() => { if (ta.disabled) window.__wentDisabled = true; })
        .observe(ta, { attributes: true, attributeFilter: ['disabled'] });
    });
    await p.type('.composer textarea', '[Automated test, please ignore] draft that is never sent');
    const keptTyping = await until(() => (document.querySelector('.composer textarea')?.value || '').includes('never sent'), null, 15000);
    check('typing before the messages arrive is not lost', keptTyping,
      keptTyping ? '' : JSON.stringify(await p.evaluate(() => ({
        val: document.querySelector('.composer textarea')?.value,
        len: document.querySelector('.composer textarea')?.value?.length,
        wentDisabled: window.__wentDisabled,
        disabled: document.querySelector('.composer textarea')?.disabled,
        offline: document.body.classList.contains('is-offline'),
        keys: Object.keys(localStorage), path: location.pathname }))));
    const draftOk = await until(() => Object.keys(localStorage).some((k) => k.startsWith('draft:')), null, 12000);
    check('the draft reaches the device', draftOk,
      draftOk ? '' : JSON.stringify(await p.evaluate(() => ({ keys: Object.keys(localStorage),
        val: document.querySelector('.composer textarea')?.value?.slice(0, 40),
        composers: document.querySelectorAll('.composer textarea').length,
        hidden: document.querySelector('.composer')?.hidden, path: location.pathname }))));
    await p.reload({ waitUntil: 'domcontentloaded' });
    check('a draft survives a reload',
      await until(() => (document.querySelector('.composer textarea')?.value || '').includes('never sent')));
    await p.evaluate(() => {
      const t = document.querySelector('.composer textarea');
      t.value = '';
      t.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await wait(600);

    // The worker has to be in charge before pulling the plug means anything.
    await p.evaluate(() => navigator.serviceWorker.ready);
    check('the worker takes charge of the chat', await until(() => !!navigator.serviceWorker.controller, null, 15000));

    // ---------- with no signal ----------
    await p.goto(new URL(first.href, BASE).href, { waitUntil: 'domcontentloaded' });
    await until(() => document.querySelectorAll('.messages [data-id]').length > 0);
    const readOnline = await msgIds();
    // Park mid-topic before pulling the plug, so that reading it offline has a place to return to.
    await p.evaluate(async () => {
      const s = document.querySelector('.scroller');
      s.scrollTop = Math.round((s.scrollHeight - s.clientHeight) / 2);
      s.dispatchEvent(new Event('scroll'));
      await new Promise((r) => setTimeout(r, 900));
    });
    const parkedOffline = await restedTop();
    // Wait for the copy on the device to actually contain the message being parked on, rather than
    // guessing at how long the write takes. If it never arrives, the check below will say so.
    const topicId = +first.href.replace(/\/$/, '').split('/').pop();
    const copyHas = (id) => p.evaluate(([t, want]) => new Promise((resolve) => {
      try {
        const r = indexedDB.open('chatiferous-local', 1);
        r.onsuccess = () => {
          const db = r.result;
          const req = db.transaction('keep', 'readonly').objectStore('keep').get('t:' + t);
          req.onsuccess = () => {
            const v = req.result && req.result.value;
            resolve(!!v && (v.messages || []).some((m) => m.id === want));
            db.close();
          };
          req.onerror = () => { resolve(false); db.close(); };
        };
        r.onerror = () => resolve(false);
      } catch (e) { resolve(false); }
    }), [topicId, id]);
    let inCopy = false;
    for (const end = Date.now() + 20000; !inCopy && Date.now() < end;) {
      inCopy = await copyHas(parkedOffline ? parkedOffline.id : 0);
      if (!inCopy) await wait(1500);
    }
    check('what you were reading is in the copy on the device', inCopy,
      'message ' + (parkedOffline && parkedOffline.id) + ' in topic ' + topicId);

    // The day's reading is posted into a topic, and opening that topic is what puts the readings on
    // the device — nobody should have to visit the reading page first to be ready for a morning
    // with no signal. So this is checked before any reading page is opened.
    const readingTopic = await p.evaluate(() => JSON.parse(document.body.dataset.app).readingTopic || 0);
    check('the app knows which topic the reading is posted in', readingTopic > 0, 'topic ' + readingTopic);

    const keptPages = () => p.evaluate(async () => {
      try {
        const c = await caches.open('chatiferous-pages');
        return (await c.keys()).map((r) => new URL(r.url).pathname);
      } catch (e) { return []; }
    });

    // Opening the topic is the whole test: stay on it, and let the week arrive underneath. Nothing
    // may be fetched until the topic itself has finished, and then one day at a time with a pause,
    // so this waits rather than hurries it. No reading page has been opened at this point.
    if (readingTopic) {
      await p.goto(BASE + 't/' + readingTopic, { waitUntil: 'domcontentloaded' });
      await until(() => document.querySelectorAll('.messages [data-id]').length > 0, null, 20000);
    }
    const keptCommentary = () => p.evaluate(async () => {
      try {
        const c = await caches.open('chatiferous-data');
        return (await c.keys()).filter((r) => r.url.includes('commentary.php')).length;
      } catch (e) { return 0; }
    });
    const wantChapters = await p.evaluate(() => (JSON.parse(document.body.dataset.app).readingChapters || []).length);

    // Today's reading and its commentary are fetched before the week behind them, one at a time,
    // so this waits on the whole run rather than hurrying any part of it.
    let days = await keptPages(), comm = await keptCommentary();
    for (const end = Date.now() + 180000; (days.length < 8 || comm < wantChapters) && Date.now() < end;) {
      await wait(3000);
      days = await keptPages();
      comm = await keptCommentary();
    }
    check('the week of readings is kept without being asked for', days.length >= 6,
      days.length + ' days kept: ' + days.slice(0, 3).join(' '));
    check('and the commentary on today\'s chapters, without opening them',
      wantChapters > 0 && comm >= wantChapters, comm + ' of ' + wantChapters + ' chapters');

    // Today's reading and a commentary on it, so the offline checks below are about remembering
    // what was looked at rather than guessing what will be wanted.
    await p.goto(BASE + 'reading', { waitUntil: 'domcontentloaded' });
    const readingSeen = await until(() => document.querySelectorAll('.b-chapter .v[data-v]').length > 0, null, 20000);
    const commentarySeen = readingSeen && await p.evaluate(() => {
      const n = document.querySelector('.vn[data-v]');
      if (!n) return false;
      n.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      return true;
    }).then(() => until(() => !!document.querySelector('.b-comm-page'), null, 20000), () => false);
    check('the reading and a commentary can be read at all', readingSeen && commentarySeen,
      'reading ' + readingSeen + ', commentary ' + commentarySeen);
    await wait(2500);

    const keptTopics = () => p.evaluate(() => new Promise((resolve) => {
      try {
        const r = indexedDB.open('chatiferous-local', 1);
        r.onsuccess = () => {
          const db = r.result;
          const req = db.transaction('keep', 'readonly').objectStore('keep').getAllKeys();
          req.onsuccess = () => { resolve(req.result.filter((k) => String(k).startsWith('t:')).length); db.close(); };
          req.onerror = () => { resolve(0); db.close(); };
        };
        r.onerror = () => resolve(0);
      } catch (e) { resolve(0); }
    }));
    let copies = await keptTopics();
    for (const end = Date.now() + 120000; copies < Math.min(topics.length, 6) && Date.now() < end;) {
      await wait(3000);
      copies = await keptTopics();
    }
    check('topics are kept without being opened', copies >= 3, copies + ' of ' + topics.length + ' topics kept');

    await p.setOfflineMode(true);
    await p.goto(new URL(first.href, BASE).href, { waitUntil: 'domcontentloaded' }).catch(() => {});
    check('the chat opens with no signal', await until(() => !!document.querySelector('.app, .messages'), null, 12000),
      (await p.title()) || '(no title)');
    check('the topic list is there with no signal', await until(() => document.querySelectorAll('.topic').length > 1));

    const readOffline = await msgIds().catch(() => []);
    check('a topic read earlier still reads with no signal',
      readOffline.length > 0 && readOffline.some((id) => readOnline.includes(id)),
      readOffline.length + ' messages, ' + readOnline.length + ' seen online');

    // The whole point of keeping a copy: with no signal you open where you were reading, not at
    // the top of whatever happened to be saved.
    const landedOffline = await restedTop(8000);
    check('with no signal it still lands where you were reading',
      !!landedOffline && !!parkedOffline && await onScreen(parkedOffline.id).catch(() => false),
      JSON.stringify({ landedOffline, wanted: parkedOffline }));

    // A topic nobody opened before the signal went. This is the point of keeping a copy at all:
    // on a plane the topic you want is rarely the one you happened to read last.
    const keptIds = await p.evaluate(() => new Promise((resolve) => {
      try {
        const r = indexedDB.open('chatiferous-local', 1);
        r.onsuccess = () => {
          const db = r.result;
          const req = db.transaction('keep', 'readonly').objectStore('keep').getAllKeys();
          req.onsuccess = () => {
            resolve(req.result.filter((k) => String(k).startsWith('t:')).map((k) => String(k).slice(2)));
            db.close();
          };
          req.onerror = () => { resolve([]); db.close(); };
        };
        r.onerror = () => resolve([]);
      } catch (e) { resolve([]); }
    }));
    const unvisited = topics.find((h) => !probed.includes(h)
      && keptIds.includes(h.replace(/\/$/, '').split('/').pop()));
    if (unvisited) {
      await p.goto(new URL(unvisited, BASE).href, { waitUntil: 'domcontentloaded' }).catch(() => {});
      check('a topic never opened still reads with no signal',
        await until(() => document.querySelectorAll('.messages [data-id]').length > 0, null, 15000),
        unvisited);
      await p.goto(new URL(first.href, BASE).href, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await until(() => document.querySelectorAll('.messages [data-id]').length > 0, null, 12000);
    }

    check('with no signal the app says so', await until(() => document.body.classList.contains('is-offline')));
    // Sending is stopped; writing is not. Taking the box away mid-sentence loses what someone was
    // typing, which is worse than the wait — so the box stays live and the words wait in the draft.
    check('with no signal nothing can be sent',
      await until(() => document.querySelector('.composer .send')?.disabled === true, null, 12000));
    check('with no signal you can still write',
      await p.evaluate(() => document.querySelector('.composer textarea')?.disabled === false));

    // ---------- the readings, which are the point of the thing ----------
    // Today's reading and the commentary chosen for it matter most; the week behind it is worth
    // having but must never be fetched ahead of them.
    check('today\'s reading opens with no signal',
      await until(() => document.querySelectorAll('.b-chapter .v[data-v]').length > 0, null, 12000)
        || await p.goto(BASE + 'reading', { waitUntil: 'domcontentloaded' }).then(
          () => until(() => document.querySelectorAll('.b-chapter .v[data-v]').length > 0), () => false));

    const week = [];
    for (let i = 1; i <= 7; i++) {
      const d = new Date(Date.now() - i * 86400000).toISOString().slice(0, 10);
      const ok = await p.goto(BASE + 'reading/' + d, { waitUntil: 'domcontentloaded' })
        .then(() => until(() => document.querySelectorAll('.b-chapter .v[data-v]').length > 0, null, 6000), () => false);
      week.push(ok);
    }
    check('the week behind it opens with no signal too',
      week.filter(Boolean).length >= 5, week.filter(Boolean).length + ' of 7 days');


    check('the chosen commentary opens with no signal',
      await until(() => !!document.querySelector('.b-comm-page, .b-comm-wait'), null, 6000)
        || await p.evaluate(() => {
          document.querySelector('.vn[data-v]')?.click();
          return true;
        }).then(() => until(() => !!document.querySelector('.b-comm-page'), null, 8000), () => false));

    // ---------- and back ----------
    await p.setOfflineMode(false);
    await p.goto(new URL(first.href, BASE).href, { waitUntil: 'domcontentloaded' });
    check('the chat catches up when the signal returns',
      await until(() => !document.body.classList.contains('is-offline')
        && document.querySelectorAll('.messages [data-id]').length > 0, null, 15000));

    // ---------- and afterwards ----------
    // Logging out already clears the message key and drafts; a local copy of the conversation
    // belongs in the same sweep, or it waits on the device for whoever signs in next.
    await p.evaluate(() => document.querySelector('form[action$="logout.php"] button')?.click());
    await wait(3000);
    // Nothing of the conversation may be left for whoever signs in next: no kept messages, no
    // cached pages of them, no drafts. The downloaded Bibles are left alone — public text, and
    // expensive to fetch again.
    const left = await p.evaluate(async () => {
      const kept = await new Promise((resolve) => {
        try {
          const r = indexedDB.open('chatiferous-local', 1);
          r.onsuccess = () => {
            const db = r.result;
            const req = db.transaction('keep', 'readonly').objectStore('keep').getAllKeys();
            req.onsuccess = () => { resolve(req.result.length); db.close(); };
            req.onerror = () => { resolve(-1); db.close(); };
          };
          r.onerror = () => resolve(-1);
        } catch (e) { resolve(-1); }
      });
      // What is left in the caches matters, not which caches exist: the sign-in page loads the
      // app's scripts on its way past, and the worker keeps those as it keeps any asset. Scripts
      // and styles are nobody's conversation. Pages of messages are.
      const leftovers = [];
      for (const n of (await caches.keys()).filter((x) => x.startsWith('chatiferous-'))) {
        const c = await caches.open(n);
        for (const req of await c.keys()) {
          if (req.url.includes('/a.php')) continue;
          leftovers.push(n + ' ' + req.url.replace(location.origin, ''));
        }
      }
      return {
        kept,
        leftovers,
        drafts: Object.keys(localStorage).filter((k) => k.startsWith('draft:')).length,
      };
    }).catch(() => null);
    check('logging out leaves no conversation behind',
      !!left && left.drafts === 0 && left.kept === 0 && left.leftovers.length === 0,
      JSON.stringify(left));

    check('no page errors', errors.length === 0, errors.slice(0, 3).join('; '));
  } catch (e) {
    failed.push('crashed: ' + e.message.split('\n')[0]);
  } finally {
    await browser.close();
  }

  console.log(`\n${passed} passed, ${failed.length} failed`);
  failed.forEach((f) => console.log('  FAILED: ' + f));
  process.exit(failed.length ? 1 : 0);
})();
