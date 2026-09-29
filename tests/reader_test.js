// The reader: changing translation without losing your place.
//
// A day's reading is several passages from the schedule, not a chapter, and changing translation
// used to throw you out of it and into the whole Bible at the first passage's chapter — losing the
// day and the place in one go. This guards against that coming back.
//
// It reads and never writes, and signs in as the throwaway reader account.
//
//   tests/run_reader_tests.sh
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

// The verse at the top of the screen, and which of the day's passages it is in.
const AT_TOP = () => {
  const root = document.querySelector('.b-chapter');
  let passage = 0;
  for (const el of root.querySelectorAll('h2.b-ms, .v[data-v]')) {
    if (el.tagName === 'H2') { passage++; continue; }
    if (el.getBoundingClientRect().bottom > 80) {
      return { passage, verse: el.dataset.v, path: location.pathname, search: location.search };
    }
  }
  return { none: true, path: location.pathname, search: location.search };
};

(async () => {
  const browser = await puppeteer.launch({
    executablePath: process.env.CHROMIUM || '/usr/bin/chromium-browser',
    headless: 'new', args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const errors = [];
  const p = await browser.newPage();
  p.setDefaultNavigationTimeout(90000);
  p.on('pageerror', (e) => errors.push(e.message));
  await p.setViewport({ width: 420, height: 800 });     // a phone, which is how it is read
  await p.setCookie({ name: process.env.COOKIE, value: sid, domain: SITE.hostname, path: SITE.pathname, secure: true, httpOnly: true });

  const verses = () => p.waitForFunction(
    () => document.querySelectorAll('.b-chapter .v[data-v]').length > 0, { timeout: 40000 },
  ).then(() => true, () => false);

  // Picks a translation other than the one showing, and taps it.
  const switchVersion = async () => {
    await p.evaluate(() => document.querySelector('.b-version').click());
    await wait(500);
    const to = await p.evaluate(() => {
      const b = [...document.querySelectorAll('.b-versions [data-version]')].find((x) => !x.classList.contains('on'));
      if (!b) return null;
      const v = b.dataset.version;
      b.click();
      return v;
    });
    await p.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {});
    await verses();
    await wait(1500);        // the place is restored once the text has been laid out
    return to;
  };

  try {
    // ---------- a day's reading ----------
    await p.goto(BASE + 'reading', { waitUntil: 'domcontentloaded' });
    check('the day\'s reading opens', await verses());

    await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight * 0.45));
    await wait(1200);
    const before = await p.evaluate(AT_TOP);
    check('there is a verse to keep your place at', !before.none, JSON.stringify(before));

    const to = await switchVersion();
    const after = await p.evaluate(AT_TOP);
    // Being thrown to the top of the page is the very thing this is meant to prevent, and it is
    // what happens if the page's own title is ever treated as the heading to come back to.
    check('and not flung to the top of the page',
      await p.evaluate(() => window.scrollY > 200), 'scrollY ' + await p.evaluate(() => Math.round(window.scrollY)));
    // "/reading" means today, and the page settles on the dated address for that day — so the
    // dated form of the same page counts as staying put.
    check('changing translation stays in the day\'s reading',
      after.path.startsWith(before.path), 'went to ' + after.path + after.search + ' (from ' + before.path + ')');
    check('and in the translation asked for', (after.search || '').includes('v=' + to), to + ' vs ' + after.search);
    // A verse is the resolution here: the same words take a different number of lines in another
    // translation, so landing within a verse of where you were is landing where you were.
    const near = (a, b) => Math.abs(+a - +b) <= 1;
    check('and at the same passage and verse',
      after.passage === before.passage && near(after.verse, before.verse),
      JSON.stringify({ before, after, to }));

    // ---------- the top of the page is a place too ----------
    await p.goto(BASE + 'reading', { waitUntil: 'domcontentloaded' });
    await verses();
    await p.evaluate(() => window.scrollTo(0, 0));
    await wait(1200);
    await switchVersion();
    check('switching at the top of the page leaves you at the top',
      await p.evaluate(() => window.scrollY < 80),
      'scrollY ' + await p.evaluate(() => Math.round(window.scrollY)));

    // ---------- the whole Bible ----------
    // The same must hold outside a day's reading: the same chapter, at the same verse.
    await p.goto(BASE + 'read/JHN/3', { waitUntil: 'domcontentloaded' });
    await verses();
    await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight * 0.45));
    await wait(1200);
    const cBefore = await p.evaluate(AT_TOP);
    const cTo = await switchVersion();
    const cAfter = await p.evaluate(AT_TOP);
    check('a chapter keeps its place when the translation changes',
      cAfter.path === cBefore.path && near(cAfter.verse, cBefore.verse),
      JSON.stringify({ cBefore, cAfter, cTo }));

    // ---------- a heading is part of the place ----------
    // Stopping with a section heading at the top of the screen is common — it is where you pause.
    // Coming back to the verse under it would leave the heading just out of sight, which is how
    // you end up reading a passage without knowing which one it is.
    await p.goto(BASE + 'read/JHN/3', { waitUntil: 'domcontentloaded' });
    await verses();
    const parkedOnHeading = await p.evaluate(() => {
      const h = [...document.querySelectorAll('.b-chapter h2, .b-chapter h3')]
        .find((x) => !x.classList.contains('b-title') && x.getBoundingClientRect().top > 200);
      if (!h) return null;
      h.scrollIntoView({ block: 'start' });
      window.scrollBy(0, -70);
      return h.textContent.trim().slice(0, 40);
    });
    if (parkedOnHeading) {
      await wait(1200);
      const hTo = await switchVersion();
      const headingBack = await p.evaluate((want) => {
        const h = [...document.querySelectorAll('.b-chapter h2, .b-chapter h3')]
          .find((x) => x.textContent.trim().slice(0, 40) === want);
        if (!h) return { missing: true };                 // another translation words it differently
        const r = h.getBoundingClientRect();
        return { top: Math.round(r.top), onScreen: r.bottom > 0 && r.top < window.innerHeight };
      }, parkedOnHeading);
      check('stopping at a heading comes back to the heading',
        headingBack.missing || headingBack.onScreen,
        JSON.stringify({ parkedOnHeading, headingBack, hTo }));
    }

    // ---------- the back arrow ----------
    // Arriving from a day's reading, the arrow out of a chapter goes back to that reading; arriving
    // cold, it keeps the page's own destination. Stepping between chapters is not "coming from"
    // anywhere, or the arrow would retrace every step.
    await p.goto(BASE + 'reading', { waitUntil: 'domcontentloaded' });
    await verses();
    const readingPath = await p.evaluate(() => location.pathname);
    await p.evaluate(() => {
      const a = document.createElement('a');
      a.href = 'read/JHN/3';
      a.id = 'test-hop';
      document.body.appendChild(a);
      a.click();
    });
    await p.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {});
    await verses();
    await wait(600);
    const arrow = await p.evaluate(() => {
      const a = document.querySelector('.bar-back');
      return a ? new URL(a.href, location.origin).pathname : null;
    });
    check('the back arrow returns to the reading you came from', arrow === readingPath,
      'arrow to ' + arrow + ' (came from ' + readingPath + ')');

    await p.goto(BASE + 'read/JHN/3', { waitUntil: 'domcontentloaded' });
    await verses();
    const cold = await p.evaluate(() => {
      const a = document.querySelector('.bar-back');
      return a ? new URL(a.href, location.origin).pathname : null;
    });
    check('and keeps its own destination when you arrive cold', cold !== readingPath, 'arrow to ' + cold);

    // ---------- the commentary, full screen or not ----------
    // With the setting off it is a sheet and the chapter shows above it; with it on it covers the
    // screen, so nothing of the text is left underneath.
    await p.goto(BASE + 'read/ROM/12', { waitUntil: 'domcontentloaded' });
    await verses();
    // The setting is changed with the commentary closed — tapping anything outside a sheet
    // dismisses it, the menu included — and then the commentary is opened to see the effect.
    const openCommentary = async () => {
      await p.evaluate(() => {
        document.querySelector('.b-comm')?.remove();
        document.querySelector('.vn[data-v]')?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      });
      // The sheet appears at once saying "Looking…"; what matters is that it has settled, with
      // either the commentary in it or a plain statement that there is none.
      return p.waitForFunction(() => {
        const s = document.querySelector('.b-comm');
        if (!s) return false;
        return !!s.querySelector('.b-comm-page')
          || (!!s.querySelector('.b-comm-wait') && !/Looking/.test(s.textContent));
      }, { timeout: 30000 }).then(() => true, () => false);
    };
    const coverage = () => p.evaluate(() => {
      const c = document.querySelector('.b-comm');
      return c ? Math.round(c.getBoundingClientRect().height / window.innerHeight * 100) : 0;
    });

    check('the commentary opens', await openCommentary());
    const asSheet = await coverage();
    check('as a sheet it leaves the chapter showing', asSheet > 50 && asSheet < 90, asSheet + '% of the screen');

    await p.evaluate(() => {
      document.querySelector('.b-comm')?.remove();
      document.querySelector('[data-set="commfull"]').click();
    });
    await wait(500);
    check('the setting says so', await p.evaluate(() => document.querySelector('[data-val="commfull"]').textContent.trim()) === 'Full screen');
    await openCommentary();
    const full = await coverage();
    check('set to full screen it covers the text', full >= 98, full + '% of the screen');

    // There must be a way back to the text, and with the commentary covering the screen there is
    // nothing outside it to tap — so the way out has to be inside it.
    const shown = await p.evaluate(() => {
      const sheet = document.querySelector('.b-comm');
      const v = sheet && sheet.querySelector('.b-comm-verse');
      return {
        sheet: !!sheet,
        block: !!v,
        hidden: v ? v.hidden : null,
        text: v ? v.textContent.replace(/\s+/g, ' ').trim().slice(0, 70) : '',
        waiting: !!(sheet && sheet.querySelector('.b-comm-wait')),
        close: !!(sheet && sheet.querySelector('.b-comm-close')),
      };
    });
    check('the verse itself is at the top of the commentary', shown.text.length > 20, JSON.stringify(shown));

    check('and there is a way back to the text', shown.close && await p.evaluate(() => {
      document.querySelector('.b-comm-close').click();
      return true;
    }).then(() => wait(400)).then(() => p.evaluate(() => !document.querySelector('.b-comm'))));

    await p.evaluate(() => {
      document.querySelector('.b-comm')?.remove();
      document.querySelector('[data-set="commfull"]').click();
    });
    await wait(400);

    // ---------- a day's reading is several passages ----------
    // Every passage on the page has verse 1, 2, 3… Tapping one in the second passage must ask about
    // that passage, not about whichever chapter happened to come first on the page.
    await p.goto(BASE + 'reading', { waitUntil: 'domcontentloaded' });
    await verses();
    const parts = await p.evaluate(() => document.querySelectorAll('.b-part').length);
    check('each passage says which chapter it is', parts > 0, parts + ' passages marked');
    if (parts > 1) {
      const tapped = await p.evaluate(() => {
        const part = document.querySelectorAll('.b-part')[1];
        const num = part.querySelector('.vn[data-v]');
        if (!num) return null;
        const want = part.dataset.book + ' ' + part.dataset.chapter;
        num.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        return { want, verse: num.dataset.v };
      });
      if (tapped) {
        const ready = await p.waitForFunction(
          () => !!document.querySelector('.b-comm .b-comm-verse, .b-comm .b-comm-wait'),
          { timeout: 25000 }).then(() => true, () => false);
        const got = await p.evaluate(() => ({
          label: document.querySelector('.b-comm-ref')?.textContent.trim() || '',
          none: !!document.querySelector('.b-comm-wait'),
        }));
        // A passage none of the chosen works comments on says so, and that is not a failure of
        // attribution — but when there is a comment, its label must name the passage tapped
        // rather than the page, which on a day's reading is headed by the date.
        check('the commentary belongs to the passage you tapped',
          ready && (got.none || (got.label !== '' && !got.label.startsWith('Reading for'))),
          JSON.stringify({ ...tapped, ...got }));
      }
    }

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
