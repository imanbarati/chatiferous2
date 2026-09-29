  // What this device keeps of the conversation, so that it can be read again with no signal.
  //
  // Only what has actually been looked at: the topic list, and the last page of messages seen in
  // each topic a member opened. Nothing is kept for a topic never opened, nothing is fetched ahead
  // of time to fill it, and nothing leaves the device. It is this screen's memory of what it
  // showed, and logging out clears it along with the drafts and the direct-message key.
  //
  // A direct message is kept exactly as it arrived — still sealed. The device key opens it on the
  // way to the screen, as it does online; the plain text is never written down here.
  const LOCAL_DB = 'chatiferous-local';
  const LOCAL_STORE = 'keep';

  function localDb() {
    return new Promise((resolve, reject) => {
      let r;
      try { r = indexedDB.open(LOCAL_DB, 1); } catch (e) { reject(e); return; }
      r.onupgradeneeded = () => {
        if (!r.result.objectStoreNames.contains(LOCAL_STORE)) r.result.createObjectStore(LOCAL_STORE);
      };
      r.onsuccess = () => resolve(r.result);
      r.onerror = () => reject(r.error);
      r.onblocked = () => reject(new Error('blocked'));
    });
  }

  // Keeping a copy must never get in the way of what is on screen: a private window, a device with
  // no room left, or storage turned off all end up here, and all of them mean "carry on without it".
  async function localSave(key, value) {
    try {
      const db = await localDb();
      await new Promise((resolve, reject) => {
        const tx = db.transaction(LOCAL_STORE, 'readwrite');
        tx.objectStore(LOCAL_STORE).put({ at: Date.now(), value }, key);
        tx.oncomplete = resolve;
        tx.onerror = tx.onabort = () => reject(tx.error);
      });
      db.close();
    } catch (e) { /* no copy this time; reading online is unaffected */ }
  }

  async function localLoad(key) {
    try {
      const db = await localDb();
      const got = await new Promise((resolve, reject) => {
        const tx = db.transaction(LOCAL_STORE, 'readonly');
        const req = tx.objectStore(LOCAL_STORE).get(key);
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
      });
      db.close();
      return got ? got.value : null;
    } catch (e) {
      return null;
    }
  }

  // The copy is first written when a topic opens: one page, as the server sent it. But reading is
  // scrolling back, which fetches more, and the whole point of the copy is to come back to where
  // reading got to. So the copy is brought up to date with what is actually on screen.
  //
  // Never for a direct message: that copy stays exactly as it arrived, still sealed. What is on
  // screen there has been opened with this device's key, and it is not written down.
  // Throttled, because it writes the whole page of messages: often enough that putting the phone
  // down mid-topic is safe, rarely enough that scrolling doesn't grind. A page being closed forces
  // it, though a write started during unload may not finish — which is why it also runs earlier.
  let lastKeep = 0;
  async function keepView(force = false) {
    const v = state.view;
    if (!force && Date.now() - lastKeep < 5000) return;
    lastKeep = Date.now();
    if (!v || v.topic.kind === 'dm' || !v.messages.length) return;
    const kept = await localLoad('t:' + v.topic.id);
    if (!kept) return;
    const MAX = 400;                     // enough to come back into, not the whole history
    const messages = v.messages.slice(-MAX);
    await localSave('t:' + v.topic.id, {
      ...kept,
      users: state.users,
      topic: v.topic,
      pins: v.pins,
      messages,
      has_older: v.hasOlder || messages.length < v.messages.length,
      has_newer: v.hasNewer,
      first_unread: v.firstUnread,
      last_read_id: v.lastRead,
    });
  }

  // The day's reading is posted into a topic, and that topic is where people are when the reading
  // is on their mind — so opening it is what puts the readings on this device. Today's comes first
  // and the week behind it follows, one at a time, well after the topic itself has finished
  // loading: it must never be in the way of the messages somebody came to read.
  let warmedReadings = false;
  function warmReadings() {
    if (warmedReadings || !APP.readingDate || !navigator.serviceWorker?.controller) return;
    warmedReadings = true;
    const urls = [APP.base + 'reading/' + APP.readingDate];
    // The commentary on today's chapters comes before the week behind it: it is what someone
    // actually reads alongside the passage, and the older days are a nicety by comparison.
    for (const ch of APP.readingChapters || []) {
      const [book, chapter] = ch.split('/');
      urls.push(`${APP.base}api/commentary.php?b=${book}&c=${chapter}&verse=0`);
    }
    for (let i = 1; i <= 7; i++) {
      const d = new Date(APP.readingDate + 'T12:00:00Z');
      d.setUTCDate(d.getUTCDate() - i);
      urls.push(APP.base + 'reading/' + d.toISOString().slice(0, 10));
    }
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 2000));
    idle(() => setTimeout(() => {
      navigator.serviceWorker.controller?.postMessage({ type: 'warm', urls });
    }, 3000));
  }

  // The latest page of every topic, not only the ones already opened. On a plane the topic you
  // want is rarely the one you happened to read last, and a chat that can only show you where
  // you have already been is not much of a chat.
  //
  // It runs last of everything: after the lists, after whatever topic is open, and after the
  // readings. One topic at a time with a pause between, and it stops at the first refusal rather
  // than pushing a host that has just said no. Direct messages are left alone — their copy is
  // written when you open them, still sealed, and there is no reading them without the key.
  const WARM_AGAIN_MS = 6 * 60 * 60 * 1000;      // a copy older than this is worth replacing
  let warmedTopics = false;

  async function localAge(key) {
    try {
      const db = await localDb();
      const got = await new Promise((resolve, reject) => {
        const tx = db.transaction(LOCAL_STORE, 'readonly');
        const req = tx.objectStore(LOCAL_STORE).get(key);
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
      });
      db.close();
      return got ? Date.now() - (got.at || 0) : Infinity;
    } catch (e) {
      return Infinity;
    }
  }

  // There are far more topics than anyone reads in a week — dozens of them — and asking for each
  // in turn is dozens of requests, every one of which a busy host may refuse. So the server packs
  // the newest page of the most recently active topics into a single reply and this unpacks it:
  // about a quarter of a megabyte over the wire, built in a fraction of a second, one request.
  //
  // Topics already here and fresh are named in the asking, so they are not sent twice.
  async function warmTopics() {
    if (warmedTopics || !state.topics.length) return;
    warmedTopics = true;
    const skip = [];
    for (const t of state.topics) {
      if (t.kind === 'dm') continue;
      if (await localAge('t:' + t.id) < WARM_AGAIN_MS) skip.push(t.id);
    }
    try {
      const bundle = await api('offline.php', skip.length ? { skip: skip.join(',') } : {});
      for (const [id, page] of Object.entries(bundle.topics || {})) {
        await localSave('t:' + id, page);
      }
    } catch (e) {
      warmedTopics = false;      // no signal, or the host has had enough: worth trying again
    }
  }
