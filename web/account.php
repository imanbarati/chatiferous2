<?php
// First visit: finish setting up (username + password). Later: account settings.
require __DIR__ . '/boot.php';
require APP_DIR . '/lib/avatars.php';

$user = require_login(true);
$first_time = $user['password_hash'] === null;
// Just confirmed with Telegram via "Forgot your password?": no current password needed.
$reset_ok = !$first_time && time() - (int)($_SESSION['pw_reset_ok'] ?? 0) < 10 * 60;
$error = null;
$saved = false;

// Profile photo: upload a new one, or remove it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['photo', 'remove_photo'], true)) {
    check_csrf();
    if ($_POST['action'] === 'remove_photo') {
        remove_avatar((int)$user['id']);
        $saved = 'photo_removed';
    } elseif (is_uploaded_file($_FILES['photo']['tmp_name'] ?? '') && $_FILES['photo']['size'] <= 25 * 1024 * 1024
        && save_avatar((int)$user['id'], $_FILES['photo']['tmp_name'], 'upload')) {
        $saved = 'photo_saved';
    } else {
        $error = 'That photo couldn’t be used. Please try a JPEG or PNG image.';
    }
    $user = q('SELECT * FROM users WHERE id = ?', [$user['id']])->fetch();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $display = trim((string)($_POST['display_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    $current = (string)($_POST['current'] ?? '');
    $changing_password = $first_time || $password !== '';

    if ($display === '' || mb_strlen($display) > 64) {
        $error = 'Please enter a name of up to 64 characters.';
    } elseif (!valid_username($username)) {
        $error = 'Usernames are 3–32 characters: letters, numbers and underscores, starting with a letter.';
    } elseif (q('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $user['id']])->fetch()) {
        $error = 'That username is taken. Please choose another.';
    } elseif ($changing_password && strlen($password) < 8) {
        $error = 'Please choose a password of at least 8 characters.';
    } elseif ($changing_password && $password !== $confirm) {
        $error = 'The two passwords don’t match.';
    } elseif ($reset_ok && $password === '') {
        $error = 'Please choose your new password.';
    } elseif (!$first_time && !$reset_ok && $password !== '' && !password_verify($current, $user['password_hash'])) {
        $error = 'Your current password isn’t right.';
    } else {
        q('UPDATE users SET display_name = ?, username = ? WHERE id = ?', [$display, $username, $user['id']]);
        if ($changing_password) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            end_other_sessions((int)$user['id']);
            if ($reset_ok) {
                unset($_SESSION['pw_reset_ok']);
                auth_event('password_reset', (int)$user['id'], $user['telegram_id'] ? (int)$user['telegram_id'] : null);
                $reset_ok = false;
            }
        }
        if ($first_time) {
            redirect('');
        }
        $saved = true;
        $user = q('SELECT * FROM users WHERE id = ?', [$user['id']])->fetch();
    }
}

$suggested = $user['username'] ?? '';
if ($suggested === '' && valid_username($_SESSION['tg_username'] ?? '')
    && !q('SELECT 1 FROM users WHERE username = ?', [$_SESSION['tg_username']])->fetch()) {
    $suggested = $_SESSION['tg_username'];
}

page_start($first_time ? 'Welcome' : 'My account', $first_time ? [] : ['back' => '', 'heading' => 'My account']);
?>
<div class="card">
  <div class="profile-head">
    <?= avatar($user['display_name'], (int)$user['color_index'], 'xl', avatar_url($user)) ?>
    <div>
      <h1><?= $first_time ? 'Welcome, ' . h($user['display_name']) . '!' : h($user['display_name']) ?></h1>
      <?php if ($first_time): ?>
        <p class="muted">One last step: choose a username and password. You can use them to sign in next time, or keep using Log in with Telegram.</p>
      <?php endif ?>
    </div>
  </div>
  <?php notice($error, 'error') ?>
  <?php notice(match ($saved) { 'photo_saved' => 'Your photo is saved.', 'photo_removed' => 'Your photo is removed.', true => 'Saved.', default => null }) ?>
  <?php if (!$first_time): ?>
  <form method="post" enctype="multipart/form-data" class="photo-form">
    <?= csrf_field() ?>
    <label>Profile photo
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" required>
    </label>
    <div class="row-buttons left">
      <button class="primary pad" name="action" value="photo">Use this photo</button>
    </div>
  </form>
  <?php if ($user['avatar_path']): ?>
  <form method="post" class="photo-form">
    <?= csrf_field() ?>
    <button class="link danger" name="action" value="remove_photo">Remove my photo</button>
  </form>
  <?php endif ?>
  <?php endif ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Your name, as the group sees it
      <input name="display_name" required maxlength="64" value="<?= h($_POST['display_name'] ?? $user['display_name']) ?>">
    </label>
    <label>Username
      <input name="username" required maxlength="32" autocomplete="username" autocapitalize="none"
        value="<?= h($_POST['username'] ?? $suggested) ?>">
    </label>
    <?php if ($reset_ok): ?>
      <p class="notice">Telegram confirmed it’s you. Choose a new password.</p>
    <?php elseif (!$first_time): ?>
      <p class="small muted">To change your password, fill in all three boxes below. Otherwise leave them empty.</p>
      <label>Current password
        <input type="password" name="current" autocomplete="current-password">
      </label>
      <?php if ($user['telegram_id'] && telegram_login_enabled()): ?>
        <p class="small"><a href="<?= url('forgot.php') ?>">Forgot it?</a></p>
      <?php endif ?>
    <?php endif ?>
    <label><?= $first_time ? 'Password' : 'New password' ?>
      <input type="password" name="password" minlength="8" autocomplete="new-password" <?= $first_time || $reset_ok ? 'required' : '' ?>>
    </label>
    <label>Type it again
      <input type="password" name="confirm" minlength="8" autocomplete="new-password" <?= $first_time || $reset_ok ? 'required' : '' ?>>
    </label>
    <button class="primary"><?= $first_time ? 'Continue' : 'Save' ?></button>
  </form>
</div>
<p class="small muted credits">Topic icons and wallpaper symbols from <a href="https://game-icons.net" target="_blank" rel="noopener">game-icons.net</a> by Lorc, Delapouite, Skoll, Carl Olsen and Seregacthtuf, used under <a href="https://creativecommons.org/licenses/by/3.0/" target="_blank" rel="noopener">CC BY 3.0</a>.</p>
<?php page_end();
