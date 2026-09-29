<?php
// Opening an invite link: create an account (or take over an imported one) without Telegram.
require __DIR__ . '/boot.php';
require APP_DIR . '/lib/actions.php';
require APP_DIR . '/lib/invites.php';

$code = (string)($_GET['c'] ?? $_POST['c'] ?? '');
$inv = usable_invite($code);
$error = null;

if (current_user() && !($inv && $inv['user_id'])) {
    redirect('');
}
if (!$inv) {
    auth_event('denied', null, null, 'invite link not usable: ' . substr($code, 0, 16));
}
$target = $inv && $inv['user_id'] ? q('SELECT * FROM users WHERE id = ?', [$inv['user_id']])->fetch() : null;

if ($inv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (too_many_failures()) {
        $error = 'Too many attempts. Please wait 15 minutes and try again.';
    } elseif ((string)($_POST['password'] ?? '') !== (string)($_POST['confirm'] ?? '')) {
        $error = 'The two passwords don’t match.';
    } else {
        try {
            $user = redeem_invite($inv, trim((string)($_POST['display_name'] ?? '')), trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''));
            log_in($user, 'login_password');
            redirect('');
        } catch (ActionError $e) {
            $error = $e->getMessage();
        }
    }
}

page_start('Join', ['bare' => true, 'main_class' => 'center']);
?>
<div class="card signin">
  <?= group_avatar() ?>
  <h1><?= h(config('group_name')) ?></h1>
  <?php if (!$inv): ?>
    <p>This invite link has expired, been used up, or been canceled.</p>
    <p class="small muted">Please ask whoever sent it for a new one. Already a member? <a href="<?= url('login.php') ?>">Sign in</a>.</p>
  <?php else: ?>
    <p class="muted"><?= $target
        ? 'This link lets you sign in as <strong>' . h($target['display_name']) . '</strong> and set your own username and password.'
        : 'You’ve been invited to join. Choose how you’ll appear and a password.' ?></p>
    <?php notice($error, 'error') ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="c" value="<?= h($code) ?>">
      <label>Your name, as the group will see it
        <input name="display_name" required maxlength="64" autocomplete="name" value="<?= h($_POST['display_name'] ?? $target['display_name'] ?? '') ?>">
      </label>
      <label>Username (for signing in)
        <input name="username" required maxlength="32" autocomplete="username" autocapitalize="none" value="<?= h($_POST['username'] ?? $target['username'] ?? '') ?>">
      </label>
      <label>Password (at least 8 characters)
        <input type="password" name="password" required minlength="8" autocomplete="new-password">
      </label>
      <label>Type it again
        <input type="password" name="confirm" required minlength="8" autocomplete="new-password">
      </label>
      <button class="primary"><?= $target ? 'Save and sign in' : 'Join' ?></button>
    </form>
    <?php if (!$target && telegram_login_enabled()): ?>
      <p class="small muted">Already in the group on Telegram? You don’t need this: <a href="<?= url('login.php') ?>">sign in with Telegram</a> instead.</p>
    <?php endif ?>
  <?php endif ?>
</div>
<?php page_end();
