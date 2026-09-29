  // Attaching things: the + menu, photos and files (resized and, in a DM, sealed before upload), and
  // making a poll.

  // Takes a chosen photo or file: shows it in the strip at once and starts sending it, so it's
  // usually ready by the time the message is.
  // A photo off a phone is 3-12 MB and several times wider than any screen that will show it, and
  // the server shrinks it to this same size on arrival. Doing it here instead means that on a slow
  // connection you send one megabyte rather than ten.
  //
  // Only JPEG, which is what cameras produce: re-encoding a PNG or a WebP would flatten any
  // transparency it has onto black.
  const PHOTO_EDGE = 2560;
  async function shrinkPhoto(file) {
    if (file.type !== 'image/jpeg') return file;
    let bmp;
    // from-image so a photo taken sideways stays the way up its EXIF says.
    try { bmp = await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { return file; }
    const scale = Math.min(1, PHOTO_EDGE / Math.max(bmp.width, bmp.height));
    if (scale === 1) { bmp.close?.(); return file; }
    const w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    c.getContext('2d').drawImage(bmp, 0, 0, w, h);
    bmp.close?.();
    const small = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.85));
    // A photo already smaller than we'd manage is left as it came.
    if (!small || small.size >= file.size) return file;
    return new File([small], file.name, { type: 'image/jpeg', lastModified: file.lastModified });
  }

  function addUpload(file) {
    const key = Math.random().toString(36).slice(2);
    const item = { key, file, id: 0, status: 'uploading', url: file.type.startsWith('image/') ? URL.createObjectURL(file) : '' };
    state.uploads.push(item);
    renderUploads();          // the strip shows the photo at once, while it is still being made ready
    const v = state.view;
    shrinkPhoto(file).then(async (photo) => {
      if (v?.topic.kind === 'dm') {   // sealed on this device before it leaves
        const ck = await convKey(v.topic);
        if (!ck) throw new Error('This conversation is still locked on this device.');
        const { sealed, meta } = await sealForUpload(photo, ck);
        item.meta = meta;
        sendUpload(item, sealed, true);
        return;
      }
      sendUpload(item, photo, false);
    }).catch((e) => { item.status = 'failed'; toast(e.message); renderUploads(); });
  }

  // Uploads one file. Sealed files go up as bytes with no name: the server can't read them.
  function sendUpload(item, file, sealed) {
    const form = new FormData();
    form.append('file', file, sealed ? 'sealed' : file.name);
    if (sealed) form.append('sealed', '1');
    const xhr = new XMLHttpRequest();
    xhr.open('POST', APP.base + 'api/upload.php');
    xhr.setRequestHeader('X-CSRF', APP.csrf);
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) { item.progress = Math.round((e.loaded / e.total) * 100); renderUploads(); }
    };
    xhr.onload = () => {
      let data = {};
      try { data = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
      if (xhr.status === 200 && data.id) { item.id = data.id; item.status = 'done'; if (item.meta) item.meta.id = data.id; }
      else { item.status = 'failed'; toast(data.error || 'The upload failed. Please try again.'); }
      renderUploads();
    };
    xhr.onerror = () => { item.status = 'failed'; toast('The upload failed. Please check your connection.'); renderUploads(); };
    xhr.send(form);
  }

  // The strip of things waiting to be sent, with progress and a way to remove each one.
  function renderUploads() {
    const strip = $('.att-strip', chatEl);
    if (!strip) return;
    strip.hidden = !state.uploads.length;
    strip.innerHTML = state.uploads.map((u) => `<div class="up ${u.status}">`
      + (u.url ? `<img src="${u.url}" alt="">` : `<span class="up-file">📄<span>${esc(u.file.name)}</span></span>`)
      + (u.status === 'uploading' ? `<span class="up-progress">${u.progress || 0}%</span>` : '')
      + (u.status === 'failed' ? '<span class="up-progress">Failed</span>' : '')
      + `<button class="up-remove" data-remove="${u.key}" aria-label="Remove">✕</button></div>`).join('');
    updateSendButton();
  }

  // Empties the strip after sending (or cancelling), releasing the previews.
  function clearUploads() {
    for (const u of state.uploads) if (u.url) URL.revokeObjectURL(u.url);
    state.uploads = [];
    renderUploads();
  }

  // ---------- Polls ----------

  // The New poll dialog: question, up to ten answers, anonymous, several answers allowed.
  function openPollDialog() {
    const dlg = document.createElement('div');
    dlg.className = 'modal';
    dlg.innerHTML = `<form class="sheet-card poll-form">
        <h2>New poll</h2>
        <label>Question<input name="q" maxlength="300" required></label>
        <div class="poll-opts">
          <label>Option 1<input name="o" maxlength="100" required></label>
          <label>Option 2<input name="o" maxlength="100" required></label>
        </div>
        <button type="button" class="link add-opt">+ Add an option</button>
        <label class="checkbox"><input type="checkbox" name="anon"> Anonymous voting</label>
        <label class="checkbox"><input type="checkbox" name="multi"> Allow several answers</label>
        <div class="row-buttons"><button type="button" class="link cancel">Cancel</button><button class="primary">Create poll</button></div>
      </form>`;
    document.body.appendChild(dlg);
    const form = $('form', dlg);
    $('input[name=q]', form).focus();
    const close = () => dlg.remove();
    $('.cancel', form).onclick = close;
    dlg.addEventListener('click', (e) => { if (e.target === dlg) close(); });
    $('.add-opt', form).onclick = () => {
      const opts = $('.poll-opts', form);
      const n = opts.children.length + 1;
      if (n > 10) return;
      opts.insertAdjacentHTML('beforeend', `<label>Option ${n}<input name="o" maxlength="100"></label>`);
      const input = opts.lastElementChild.querySelector('input');
      input.focus();
      input.scrollIntoView({ block: 'nearest' });
      if (n === 10) $('.add-opt', form).hidden = true;              // the most a poll can have
      else $('.add-opt', form).scrollIntoView({ block: 'nearest' });   // keep "+ Add an option" in reach too
    };
    form.onsubmit = async (e) => {
      e.preventDefault();
      const params = new URLSearchParams();
      params.append('topic', state.view.topic.id);
      params.append('poll_question', form.q.value);
      for (const o of form.querySelectorAll('input[name=o]')) if (o.value.trim()) params.append('poll_options[]', o.value);
      if (form.anon.checked) params.append('poll_anonymous', '1');
      if (form.multi.checked) params.append('poll_multiple', '1');
      try {
        const res = await fetch(APP.base + 'api/send.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf }, body: params });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'The poll couldn’t be created.');
        close();
        upsert(data);
        state.view.firstUnread = 0;
        renderMessages();
      } catch (err) {
        toast(err.message);
      }
    };
  }

  // Casts or changes a vote.
  async function vote(messageId, optionIds) {
    const params = new URLSearchParams();
    params.append('id', messageId);
    for (const o of optionIds) params.append('options[]', o);
    try {
      const res = await fetch(APP.base + 'api/vote.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf }, body: params });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Your vote didn’t go through.');
      upsert(data);
      renderMessages({ stay: true });
    } catch (e) {
      toast(e.message);
    }
  }
