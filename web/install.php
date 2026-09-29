<?php
// How to put the group on your Home Screen (steps checked against Apple's and Google's current guidance).
require __DIR__ . '/boot.php';

require_login();
page_start('Install', ['back' => '', 'heading' => 'Install on your phone']);
?>
<div class="card install" id="install-card">
  <p id="installed-note" class="notice" hidden>✓ You’re using the installed app. Nothing more to do here.</p>

  <section data-platform="ios">
    <h1>On iPhone or iPad</h1>
    <ol class="steps">
      <li>Open this site in <strong>Safari</strong>.</li>
      <li>Tap the <strong>⋯</strong> button beside the address bar, then tap <strong>Share</strong>.<br>
        <span class="small muted">(Older iPhones: tap the Share button <span aria-hidden="true">⬆︎</span> at the bottom of the screen.)</span></li>
      <li>Scroll down and tap <strong>Add to Home Screen</strong>.</li>
      <li>Leave <strong>Open as Web App</strong> switched on, then tap <strong>Add</strong>.</li>
      <li>From now on, open the group from its icon on your Home Screen. That’s also where notifications work.</li>
    </ol>
  </section>

  <section data-platform="android">
    <h1>On Android</h1>
    <p id="android-button" hidden><button class="primary pad" id="install-now">Install the app</button></p>
    <ol class="steps">
      <li>Open this site in <strong>Chrome</strong>.</li>
      <li>Tap the <strong>⋮</strong> menu at the top right.</li>
      <li>Tap <strong>Install app</strong> (on some phones: <strong>Add to Home screen</strong>), then <strong>Install</strong>.</li>
      <li>Open the group from its new icon.</li>
    </ol>
  </section>

  <section data-platform="desktop">
    <h1>On a computer</h1>
    <p>In Chrome or Edge, click the install icon at the right of the address bar (or the ⋮ menu → <strong>Install <?= h(config('group_name')) ?></strong>).
    In Safari on a Mac, choose <strong>File → Add to Dock</strong>. Or simply bookmark this page.</p>
  </section>

  <p class="small muted">Next: <a href="<?= url('notifications.php') ?>">turn on notifications</a>.</p>
</div>
<script src="<?= asset('notify.js') ?>"></script>
<?php page_end();
