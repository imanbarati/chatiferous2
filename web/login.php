<?php
require __DIR__ . '/boot.php';

if (current_user()) {
    redirect('');
}

$errors = [
    'bad'         => 'That Telegram sign-in couldn’t be verified. Please try again.',
    'notmember'   => 'That Telegram account isn’t a member of the group. If you think this is a mistake, contact an admin.',
    'deactivated' => 'This account has been deactivated. Contact an admin if you think this is a mistake.',
    'unavailable' => 'Telegram didn’t answer just now. Please try again in a minute.',
];
$error = $errors[$_GET['e'] ?? ''] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $user = q("SELECT * FROM users WHERE username = ? AND status = 'active'", [$username])->fetch();
    if (too_many_failures($user ? (int)$user['id'] : null)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            log_in($user, 'login_password');
            redirect('');
        }
        auth_event('login_failed', $user ? (int)$user['id'] : null, null, 'username: ' . $username);
        sleep(1);
        $error = 'Wrong username or password.';
    }
}

$callback = 'https://' . $_SERVER['HTTP_HOST'] . url('auth/telegram-callback.php');

page_start('Sign in', ['bare' => true, 'main_class' => 'center']);
?>
<div class="card signin">
  <div class="group-avatar" aria-hidden="true"><?= h(config('group_emoji')) ?></div>
  <h1><?= h(config('group_name')) ?></h1>
  <p class="muted">Members only.</p>
  <?php notice($error, 'error') ?>

  <?php if (telegram_login_enabled()): ?>
  <div class="tg-login">
    <script async src="https://telegram.org/js/telegram-widget.js?22"
      data-telegram-login="<?= h(config('telegram_bot_username')) ?>"
      data-size="large" data-radius="12"
      data-auth-url="<?= h($callback) ?>"></script>
  </div>
  <p class="small muted">First time? Log in with Telegram to claim your account. If Telegram asks for your phone number, that stays with Telegram: this site never sees it.</p>

  <div class="or"><span>or</span></div>
  <?php endif ?>

  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Username
      <input name="username" autocomplete="username" autocapitalize="none" required value="<?= h($_POST['username'] ?? '') ?>">
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="primary">Sign in</button>
  </form>
  <p class="small"><a href="<?= url('forgot.php') ?>">Forgot your password?</a></p>
  <?php if (config('signin_note')): ?><p class="small muted"><?= h(config('signin_note')) ?></p><?php endif ?>
</div>
<?php page_end();
