<?php
// Turning notifications on for this device, and choosing what to be notified about.
require __DIR__ . '/boot.php';

$user = require_login();
page_start('Notifications', ['back' => '', 'heading' => 'Notifications']);
?>
<div class="card" id="push-card" data-base="<?= h(url()) ?>" data-csrf="<?= h(csrf_token()) ?>">
  <h1>Notifications on this device</h1>
  <p id="push-status" class="muted">Checking…</p>
  <div id="push-actions" class="stack"></div>
</div>

<div class="card">
  <h2 class="card-title">What to notify me about</h2>
  <form id="level-form" class="stack">
    <?php foreach (['all' => 'Every new message (except topics I mute)', 'mentions' => 'Only replies to me and @mentions', 'off' => 'Nothing'] as $v => $label): ?>
      <label class="checkbox"><input type="radio" name="level" value="<?= $v ?>" <?= $user['notify_level'] === $v ? 'checked' : '' ?>> <?= h($label) ?></label>
    <?php endforeach ?>
  </form>
  <p class="small muted">To silence a single topic, open it and choose <strong>Mute</strong> from the ⋮ menu at the top.</p>
</div>
<script src="<?= asset('notify.js') ?>"></script>
<?php page_end();
