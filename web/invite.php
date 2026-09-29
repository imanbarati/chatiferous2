<?php
// Any member can invite new people: 5 a week (admins: unlimited).
require __DIR__ . '/boot.php';
require APP_DIR . '/lib/actions.php';
require APP_DIR . '/lib/invites.php';

$me = require_login();
$new_link = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $new_link = invite_link(new_invite($me, null, (string)($_POST['note'] ?? ''), 1, 14));
        auth_event('invite_created', (int)$me['id'], null, trim((string)($_POST['note'] ?? '')));
    } catch (ActionError $e) {
        $error = $e->getMessage();
    }
}
$left = invites_left($me);
$mine = q("SELECT i.*, (SELECT GROUP_CONCAT(display_name SEPARATOR ', ') FROM users u WHERE u.invite_id = i.id) AS joined
           FROM invite_codes i WHERE i.created_by = ? AND i.user_id IS NULL ORDER BY i.id DESC LIMIT 20", [$me['id']])->fetchAll();

page_start('Invite someone', ['back' => '', 'heading' => 'Invite someone']);
?>
<?php notice($error, 'error') ?>
<?php if ($new_link): ?>
  <div class="notice new-link">
    <strong>Your invite link</strong> (works once, for 14 days). Send it to the person you’re inviting:
    <input readonly value="<?= h($new_link) ?>" class="link-box" aria-label="Invite link">
    <button class="primary pad" data-copy="<?= h($new_link) ?>">Copy link</button>
  </div>
<?php endif ?>
<div class="card">
  <?php if (telegram_login_enabled()): ?>
  <p>Invite someone who isn’t in the group on Telegram. They’ll choose a name and password. Anyone already in the Telegram group doesn’t need this: they just sign in with Telegram at <strong><?= h($_SERVER['HTTP_HOST'] . url()) ?></strong>.</p>
  <?php else: ?>
  <p>Invite someone to the group. They’ll choose a name and password.</p>
  <?php endif ?>
  <p class="small muted"><?= $left === null ? 'As an admin you can create as many invites as you like.' : "You have <strong>$left</strong> of " . MEMBER_INVITES_PER_WEEK . ' invites left this week.' ?></p>
  <?php if ($left !== 0): ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Who it’s for (optional)<input name="note" maxlength="200" placeholder="e.g. my neighbour Ann"></label>
    <button class="primary">Create invite link</button>
  </form>
  <?php endif ?>
</div>
<?php if ($mine): ?>
<h2 class="section">Your invites</h2>
<ul class="list compact">
  <?php foreach ($mine as $i):
    $state = $i['joined'] ? 'joined: ' . $i['joined'] : ($i['revoked_at'] ? 'cancelled' : ($i['expires_at'] && strtotime($i['expires_at'] . ' UTC') < time() ? 'expired' : 'not used yet')); ?>
  <li><div class="grow"><strong><?= h($i['note'] !== '' ? $i['note'] : 'Invite') ?></strong>
    <div class="small muted"><?= h(substr($i['created_at'], 0, 10)) ?> · <?= h($state) ?></div></div>
    <?php if ($state === 'not used yet'): ?><button class="link" data-copy="<?= h(invite_link($i['code'])) ?>">Copy</button><?php endif ?>
  </li>
  <?php endforeach ?>
</ul>
<?php endif ?>
<script src="<?= asset('confirm.js') ?>"></script>
<?php page_end();
