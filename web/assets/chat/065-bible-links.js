  // Bible references in messages ("John 3:16", "1 Jn 2:1-3", "Rom 8:28-30") become links into the
  // reader. Done after a message is drawn, on its text nodes only, so nothing inside links, code
  // or attachments is touched, and old messages gain links too without being changed on the server.
  //
  // It only matches a real book name followed by a number: a bare "3:16" or a time of day is left
  // alone. A missed link is better than a wrong one in the middle of a sentence.
  const BIBLE_BOOKS = {
    GEN: 'genesis|gen|ge|gn', EXO: 'exodus|exod|exo|ex', LEV: 'leviticus|lev|lv',
    NUM: 'numbers|num|nu|nm', DEU: 'deuteronomy|deut|deu|dt', JOS: 'joshua|josh|jos',
    JDG: 'judges|judg|jdg|jg', RUT: 'ruth|rut|ru',
    '1SA': '1 ?samuel|1 ?sam|1 ?sa|i ?samuel', '2SA': '2 ?samuel|2 ?sam|2 ?sa|ii ?samuel',
    '1KI': '1 ?kings|1 ?kgs|1 ?ki', '2KI': '2 ?kings|2 ?kgs|2 ?ki',
    '1CH': '1 ?chronicles|1 ?chron|1 ?chr|1 ?ch', '2CH': '2 ?chronicles|2 ?chron|2 ?chr|2 ?ch',
    EZR: 'ezra|ezr', NEH: 'nehemiah|neh|ne', EST: 'esther|esth|est', JOB: 'job',
    PSA: 'psalms|psalm|pss|psa|ps', PRO: 'proverbs|prov|pro|prv', ECC: 'ecclesiastes|eccles|eccl|ecc',
    SNG: 'song of solomon|song of songs|songs|song|sos|sng', ISA: 'isaiah|isa', JER: 'jeremiah|jer|jr',
    LAM: 'lamentations|lam', EZK: 'ezekiel|ezek|ezk|eze', DAN: 'daniel|dan|dn', HOS: 'hosea|hos',
    JOL: 'joel|jol', AMO: 'amos|amo', OBA: 'obadiah|obad|oba', JON: 'jonah|jon', MIC: 'micah|mic',
    NAM: 'nahum|nah|nam', HAB: 'habakkuk|hab', ZEP: 'zephaniah|zeph|zep', HAG: 'haggai|hag',
    ZEC: 'zechariah|zech|zec', MAL: 'malachi|mal',
    MAT: 'matthew|matt|mat|mt', MRK: 'mark|mrk|mk', LUK: 'luke|luk|lk', JHN: 'john|jhn|jn',
    ACT: 'acts|act', ROM: 'romans|rom|ro',
    '1CO': '1 ?corinthians|1 ?cor|1 ?co', '2CO': '2 ?corinthians|2 ?cor|2 ?co',
    GAL: 'galatians|gal', EPH: 'ephesians|eph', PHP: 'philippians|phil|php', COL: 'colossians|col',
    '1TH': '1 ?thessalonians|1 ?thess|1 ?th', '2TH': '2 ?thessalonians|2 ?thess|2 ?th',
    '1TI': '1 ?timothy|1 ?tim|1 ?ti', '2TI': '2 ?timothy|2 ?tim|2 ?ti', TIT: 'titus|tit',
    PHM: 'philemon|philem|phm', HEB: 'hebrews|heb', JAS: 'james|jas',
    '1PE': '1 ?peter|1 ?pet|1 ?pe', '2PE': '2 ?peter|2 ?pet|2 ?pe',
    '1JN': '1 ?john|1 ?jn', '2JN': '2 ?john|2 ?jn', '3JN': '3 ?john|3 ?jn',
    JUD: 'jude', REV: 'revelation|revelations|rev',
  };
  // Books of one chapter: "Jude 5" means the verse, not a chapter.
  const BIBLE_ONE_CHAPTER = new Set(['OBA', 'PHM', '2JN', '3JN', 'JUD']);

  const BIBLE_RE = (() => {
    const names = [];
    for (const [code, alts] of Object.entries(BIBLE_BOOKS)) {
      for (const a of alts.split('|')) names.push([a, code]);
    }
    names.sort((a, b) => b[0].length - a[0].length);     // "song of solomon" before "song"
    const alt = names.map(([a]) => a).join('|');
    return {
      re: new RegExp(`(?<![\\p{L}\\p{N}])(${alt})\\.?\\s*(\\d{1,3})(?:\\s*[:.]\\s*(\\d{1,3})(?:\\s*[-–—]\\s*(\\d{1,3}))?)?(?![\\p{L}\\p{N}:])`, 'giu'),
      code: Object.fromEntries(names.map(([a, c]) => [a.replace(/ \?/g, ' '), c])),
    };
  })();

  function bibleRefLink(match) {
    const name = match[1].toLowerCase().replace(/\s+/g, ' ');
    const code = BIBLE_RE.code[name] || BIBLE_RE.code[name.replace(/\s/g, '')];
    if (!code) return null;
    let chapter = +match[2];
    let verse = match[3] ? +match[3] : 0;
    if (BIBLE_ONE_CHAPTER.has(code) && !verse) { verse = chapter; chapter = 1; }
    return { code, chapter, verse, text: match[0] };
  }

  // Wraps every reference in an element's text nodes with a link to the reader.
  function linkBibleRefs(root) {
    if (!APP.bible || !root) return;
    for (const el of root.querySelectorAll('.text:not(.b-linked)')) {
      el.classList.add('b-linked');
      const walk = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      const nodes = [];
      let n;
      while ((n = walk.nextNode())) {
        if (!n.parentElement.closest('a, code, pre, .bref')) nodes.push(n);
      }
      for (const node of nodes) {
        const text = node.nodeValue;
        BIBLE_RE.re.lastIndex = 0;
        let m, last = 0, frag = null;
        while ((m = BIBLE_RE.re.exec(text))) {
          const ref = bibleRefLink(m);
          if (!ref) continue;
          frag ||= document.createDocumentFragment();
          frag.append(text.slice(last, m.index));
          const a = document.createElement('a');
          a.className = 'bref';
          a.href = `${APP.base}read/${ref.code}/${ref.chapter}${ref.verse ? '#v' + ref.verse : ''}`;
          a.textContent = m[0];
          frag.append(a);
          last = m.index + m[0].length;
        }
        if (frag) {
          frag.append(text.slice(last));
          node.replaceWith(frag);
        }
      }
    }
  }
