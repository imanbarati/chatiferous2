<?php
// Forgotten password: confirm who you are with "Log in with Telegram", then choose a new password
// on My account without the old one (for the next 10 minutes). Members who never used Telegram
// ask an admin for a reset link instead.
require __DIR__ . '/boot.php';

page_start('Forgot your password', ['bare' => true, 'main_class' => 'center']);
?>
<div class="card signin">
  <?= group_avatar() ?>
  <h1>Forgot your password?</h1>
  <?php if (telegram_login_enabled()):
      $_SESSION['pw_reset_intent'] = time(); ?>
    <p class="muted">Confirm it’s you with Telegram, then choose a new one.</p>
    <div class="tg-login">
      <script async src="https://telegram.org/js/telegram-widget.js?22"
        data-telegram-login="<?= h(config('telegram_bot_username')) ?>"
        data-size="large" data-radius="12"
        data-auth-url="<?= h('https://' . $_SERVER['HTTP_HOST'] . url('auth/telegram-callback.php')) ?>"></script>
    </div>
    <p class="small muted">Didn’t join through Telegram? Ask an admin for a reset link.</p>
  <?php else: ?>
    <p class="muted">Ask an admin for a reset link.</p>
  <?php endif ?>
  <p class="small"><a href="<?= url(current_user() ? 'account.php' : 'login.php') ?>">Back</a></p>
</div>
<?php page_end();
