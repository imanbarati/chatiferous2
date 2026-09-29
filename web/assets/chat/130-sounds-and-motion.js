  // Sounds and small motions: the "toc" on posting and "whoosh" on deleting (⋮ → Sounds), drawn
  // with Web Audio so there are no sound files to load; the dissolve a deleted message fades out
  // with; and readingSpot()/restoreSpot(), which hold your place in the conversation across a post
  // or a delete (otherwise the view jumps).

  // Made on the spot with Web Audio (no files): a soft "toc" when your message goes out,
  // a quiet "whoosh" when you delete one. Phones on silent stay silent; ⋮ → Sounds turns them off.
  let audioCtx = null;
  const soundsOn = () => { try { return localStorage.getItem('sounds') !== 'off'; } catch (e) { return true; } };
  // The Web Audio context, made on the first sound (browsers only allow it after a tap), or
  // nothing at all when sounds are turned off.
  function audio() {
    if (!soundsOn()) return null;
    const A = window.AudioContext || window.webkitAudioContext;
    if (!A) return null;
    audioCtx ||= new A();
    if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
    return audioCtx;
  }
  // The soft "toc" when a message goes out.
  function playToc() {
    const c = audio();
    if (!c) return;
    // A falling "toc" plus a brief higher click, so it carries on small phone speakers.
    const t = c.currentTime;
    for (const [type, f0, f1, peak, len] of [['sine', 1250, 480, 0.42, 0.09], ['triangle', 2600, 1400, 0.14, 0.035]]) {
      const o = c.createOscillator(), g = c.createGain();
      o.type = type;
      o.frequency.setValueAtTime(f0, t);
      o.frequency.exponentialRampToValueAtTime(f1, t + len * 0.55);
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(peak, t + 0.004);
      g.gain.exponentialRampToValueAtTime(0.0001, t + len);
      o.connect(g).connect(c.destination);
      o.start(t);
      o.stop(t + len + 0.02);
    }
  }
  // The "whoosh" when one is deleted.
  function playWhoosh() {
    const c = audio();
    if (!c) return;
    const t = c.currentTime, dur = 0.38;
    const buf = c.createBuffer(1, Math.floor(c.sampleRate * dur), c.sampleRate);
    const d = buf.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    const src = c.createBufferSource(), f = c.createBiquadFilter(), g = c.createGain();
    src.buffer = buf;
    f.type = 'bandpass';
    f.Q.value = 0.9;
    f.frequency.setValueAtTime(2600, t);
    f.frequency.exponentialRampToValueAtTime(350, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(0.16, t + 0.07);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    src.connect(f).connect(g).connect(c.destination);
    src.start(t);
    src.stop(t + dur);
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-sound-toggle]');
    if (!b) return;
    try { localStorage.setItem('sounds', soundsOn() ? 'off' : 'on'); } catch (err) { /* storage unavailable */ }
    $('[data-sound-label]').textContent = soundsOn() ? 'On' : 'Off';
    if (soundsOn()) playToc();
  });
  { const l = document.querySelector('[data-sound-label]'); if (l) l.textContent = soundsOn() ? 'On' : 'Off'; }

  // Where you are reading: the first message showing at the top, and how far down it sits.
  // Null when you're at the bottom (a post keeps you there anyway).
  function readingSpot() {
    const scroller = $('.scroller', chatEl);
    if (!scroller || atBottom(scroller)) return null;
    const top = scroller.getBoundingClientRect().top;
    const el = [...chatEl.querySelectorAll('.messages [data-id]')].find((x) => x.getBoundingClientRect().bottom > top);
    return el ? { anchorId: +el.dataset.id, offset: el.getBoundingClientRect().top - top } : null;
  }

  // Scroll so that message sits where it did.
  function restoreSpot(spot) {
    const scroller = $('.scroller', chatEl);
    const el = chatEl.querySelector(`.messages [data-id="${spot.anchorId}"]`);
    if (!scroller || !el) return false;
    scroller.scrollTop += el.getBoundingClientRect().top - scroller.getBoundingClientRect().top - spot.offset;
    return true;
  }

  // A deleted message dissolves (blurs, fades, drifts up), its space closes, and you stay
  // exactly where you were reading. Deleting the message you just posted from further up
  // takes you back up to where you were when you posted it.
  async function dissolveMessage(id) {
    const v = state.view, scroller = $('.scroller', chatEl);
    const el = chatEl.querySelector(`.messages [data-id="${id}"]`);
    playWhoosh();
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (el && !calm) {
      // If it's the only message under its date heading, the heading goes with it (smoothly,
      // rather than vanishing a moment later and making the chat hop).
      const chip = el.previousElementSibling, next = el.nextElementSibling;
      const lone = chip?.classList.contains('date-chip') && (!next || next.classList.contains('date-chip')) ? chip : null;
      el.classList.add('dissolving');
      lone?.classList.add('dissolving');
      await new Promise((r) => setTimeout(r, 380));
      for (const x of [el, lone].filter(Boolean)) { x.style.height = x.offsetHeight + 'px'; }
      void el.offsetHeight;
      for (const x of [el, lone].filter(Boolean)) { x.classList.add('collapsing'); x.style.height = '0px'; }
      // Wait until it has fully closed (a timer alone can cut it short and leave a hop).
      await new Promise((r) => {
        const done = () => { clearTimeout(t); el.removeEventListener('transitionend', onEnd); r(); };
        const onEnd = (e) => { if (e.target === el && e.propertyName === 'height') done(); };
        const t = setTimeout(done, 450);
        el.addEventListener('transitionend', onEnd);
      });
    }
    // Anchor on the first message still showing at the top, and put it back in the same spot.
    const top = scroller.getBoundingClientRect().top;
    const anchor = [...chatEl.querySelectorAll('.messages [data-id]')]
      .find((x) => +x.dataset.id !== id && x.getBoundingClientRect().bottom > top);
    const aId = anchor?.dataset.id, aOff = anchor ? anchor.getBoundingClientRect().top - top : 0;
    const bottom = atBottom(scroller);
    v.messages = v.messages.filter((x) => x.id !== id);
    renderMessages({ keep: true });
    const again = aId && chatEl.querySelector(`.messages [data-id="${aId}"]`);
    const rp = state.returnPoint;
    if (rp && rp.sentId === id && rp.topic === v.topic.id && restoreSpot(rp)) state.returnPoint = null;
    else if (bottom) scroller.scrollTop = scroller.scrollHeight;
    else if (again) scroller.scrollTop += again.getBoundingClientRect().top - top - aOff;
    state.stuck = atBottom(scroller);
    updateBottomButton();
    queueRead();
  }

  // Adds or removes your reaction to a message.
  async function react(id, emoji) {
    try {
      upsert(await api('react.php', { id, emoji }, true));
      renderMessages({ stay: true });
    } catch (e) {
      toast(e.message);
    }
  }
