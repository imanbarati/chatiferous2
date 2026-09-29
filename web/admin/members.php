<?php
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/actions.php';
require APP_DIR . '/lib/invites.php';

$me = require_admin();
$msg = null;
$new_link = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'invite') {
        $code = new_invite($me, null, (string)($_POST['note'] ?? ''), (int)($_POST['uses'] ?? 1), (int)($_POST['days'] ?? 14));
        $new_link = invite_link($code);
        auth_event('invite_created', (int)$me['id'], null, trim((string)($_POST['note'] ?? '')));
    } elseif ($action === 'revoke') {
        q('UPDATE invite_codes SET revoked_at = UTC_TIMESTAMP() WHERE id = ?', [(int)($_POST['invite'] ?? 0)]);
        $msg = 'That link no longer works.';
    } elseif ($action === 'claim_link') {
        $t = q("SELECT * FROM users WHERE id = ? AND role <> 'system' AND status IN ('unclaimed', 'active')", [(int)($_POST['id'] ?? 0)])->fetch();
        if ($t && $t['role'] !== 'owner') {
            $new_link = invite_link(new_invite($me, (int)$t['id'], 'claim: ' . $t['display_name'], 1, 14));
            $msg = 'Personal link for ' . $t['display_name'] . ' (one use, 14 days). Send it to them privately.';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['action'] ?? '', ['invite', 'revoke', 'claim_link'], true)) {
    $target = q('SELECT * FROM users WHERE id = ?', [(int)($_POST['id'] ?? 0)])->fetch();
    $action = $_POST['action'] ?? '';
    if (!$target || $target['role'] === 'owner' || $target['role'] === 'system' || (int)$target['id'] === (int)$me['id']) {
        $msg = 'That account can’t be changed here.';
    } elseif ($action === 'deactivate' && $target['status'] !== 'deleted') {
        q("UPDATE users SET status = 'deactivated' WHERE id = ?", [$target['id']]);
        auth_event('deactivated', (int)$target['id'], null, 'by ' . $me['display_name']);
        $msg = $target['display_name'] . ' is deactivated and signed out.';
    } elseif ($action === 'reactivate' && $target['status'] === 'deactivated') {
        $status = $target['claimed_at'] ? 'active' : 'unclaimed';
        q('UPDATE users SET status = ? WHERE id = ?', [$status, $target['id']]);
        auth_event('reactivated', (int)$target['id'], null, 'by ' . $me['display_name']);
        $msg = $target['display_name'] . ' is active again.';
    } elseif ($action === 'toggle_admin' && $target['status'] === 'active') {
        $role = $target['role'] === 'admin' ? 'member' : 'admin';
        q('UPDATE users SET role = ? WHERE id = ?', [$role, $target['id']]);
        auth_event('role_' . $role, (int)$target['id'], null, 'by ' . $me['display_name']);
        $msg = $target['display_name'] . ($role === 'admin' ? ' is now an admin.' : ' is no longer an admin.');
    }
}

$invites = q("SELECT i.*, (SELECT GROUP_CONCAT(display_name SEPARATOR ', ') FROM users u WHERE u.invite_id = i.id) AS joined
             FROM invite_codes i WHERE i.revoked_at IS NULL AND (i.expires_at IS NULL OR i.expires_at > UTC_TIMESTAMP())
             ORDER BY i.id DESC LIMIT 30")->fetchAll();

$filters = [
    'claimed'   => ["status = 'active'", 'Signed up'],
    'unclaimed' => ["status = 'unclaimed'", 'Not yet'],
    'off'       => ["status = 'deactivated'", 'Deactivated'],
];
$filter = isset($filters[$_GET['show'] ?? '']) ? $_GET['show'] : 'claimed';
$search = trim((string)($_GET['q'] ?? ''));
$counts = q("SELECT status, COUNT(*) n FROM users WHERE role <> 'system' GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$sql = "SELECT * FROM users WHERE role <> 'system' AND " . $filters[$filter][0];
$params = [];
if ($search !== '') {
    $sql .= ' AND (display_name LIKE ? OR username LIKE ?)';
    $params = ["%$search%", "%$search%"];
}
$users = q($sql . ' ORDER BY display_name', $params)->fetchAll();

$events = q("SELECT e.*, u.display_name FROM auth_events e LEFT JOIN users u ON u.id = e.user_id
             WHERE e.kind IN ('claim','join','denied','deactivated','reactivated','role_admin','role_member','invite_created')
             ORDER BY e.id DESC LIMIT 30")->fetchAll();
$event_labels = [
    'claim' => 'claimed their account', 'join' => 'joined', 'denied' => 'was refused', 'invite_created' => 'created an invite link',
    'deactivated' => 'was deactivated', 'reactivated' => 'was reactivated',
    'role_admin' => 'was made an admin', 'role_member' => 'is no longer an admin',
];

page_start('Members', ['back' => '', 'heading' => 'Members', 'sub' => ($counts['active'] ?? 0) . ' signed up · ' . ($counts['unclaimed'] ?? 0) . ' not yet']);
?>
<?php notice($msg) ?>
<?php if ($new_link): ?>
  <div class="notice new-link">
    <strong>New link</strong>, ready to send:
    <input readonly value="<?= h($new_link) ?>" class="link-box" aria-label="Invite link">
    <button class="primary pad" data-copy="<?= h($new_link) ?>">Copy link</button>
  </div>
<?php endif ?>

<details class="card invites" <?= $invites || $new_link ? '' : 'open' ?>>
  <summary><strong>Invite someone new</strong> <?php if (telegram_login_enabled()): ?><span class="muted small">(for people who aren’t in the Telegram group)</span><?php endif ?></summary>
  <form method="post" class="inline-invite">
    <?= csrf_field() ?>
    <label>Who it’s for (optional)<input name="note" maxlength="200" placeholder="e.g. Pastor Tom’s wife"></label>
    <label>How many people can use it<input name="uses" type="number" min="1" max="500" value="1"></label>
    <label>Expires after (days; 0 = never)<input name="days" type="number" min="0" max="365" value="14"></label>
    <button class="primary pad" name="action" value="invite">Create invite link</button>
  </form>
  <?php if ($invites): ?>
  <h3 class="small muted">Open links</h3>
  <ul class="list compact">
    <?php foreach ($invites as $i): ?>
    <li>
      <div class="grow">
        <strong><?= h($i['note'] !== '' ? $i['note'] : 'Invite link') ?></strong>
        <div class="small muted">Used <?= (int)$i['uses'] ?> of <?= (int)$i['max_uses'] ?>
          · <?= $i['expires_at'] ? 'expires ' . h(substr($i['expires_at'], 0, 10)) : 'never expires' ?>
          <?= $i['joined'] ? ' · joined: ' . h($i['joined']) : '' ?></div>
      </div>
      <form method="post" class="row-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="invite" value="<?= (int)$i['id'] ?>">
        <?php if ($i['uses'] < $i['max_uses']): ?><button type="button" class="link" data-copy="<?= h(invite_link($i['code'])) ?>">Copy</button><?php endif ?>
        <button class="link danger" name="action" value="revoke" data-confirm="Cancel this link? It will stop working.">Cancel</button>
      </form>
    </li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</details>

<nav class="tabs">
  <?php foreach ($filters as $key => [, $label]): ?>
    <a href="?show=<?= $key ?>" class="<?= $key === $filter ? 'on' : '' ?>"><?= h($label) ?>
      <span class="count"><?= (int)($counts[['claimed' => 'active', 'unclaimed' => 'unclaimed', 'off' => 'deactivated'][$key]] ?? 0) ?></span></a>
  <?php endforeach ?>
</nav>
<form method="get" class="search">
  <input type="hidden" name="show" value="<?= h($filter) ?>">
  <input type="search" name="q" placeholder="Search names" value="<?= h($search) ?>" aria-label="Search names">
</form>

<ul class="list">
  <?php foreach ($users as $u): ?>
  <li>
    <?= avatar($u['display_name'], (int)$u['color_index'], '', avatar_url($u)) ?>
    <div class="grow">
      <div class="name"><?= h($u['display_name']) ?>
        <?php if (in_array($u['role'], ['owner', 'admin'], true)): ?><span class="badge-role"><?= h($u['role']) ?></span><?php endif ?>
      </div>
      <div class="small muted">
        <?= $u['username'] ? '@' . h($u['username']) . ' · ' : '' ?>
        <?= $u['claimed_at'] ? 'signed up ' . h(substr($u['claimed_at'], 0, 10)) : 'not signed up yet' ?>
        <?= $u['last_login_at'] ? ' · last in ' . h(substr($u['last_login_at'], 0, 10)) : '' ?>
      </div>
    </div>
    <?php if ($u['role'] !== 'owner' && (int)$u['id'] !== (int)$me['id']): ?>
    <form method="post" class="row-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <?php if (in_array($u['status'], ['unclaimed', 'active'], true)): ?>
        <button name="action" value="claim_link" class="link" title="A personal link to sign in without Telegram"><?= $u['status'] === 'unclaimed' ? 'Claim link' : 'Reset link' ?></button>
      <?php endif ?>
      <?php if ($u['status'] === 'active'): ?>
        <button name="action" value="toggle_admin" class="link"><?= $u['role'] === 'admin' ? 'Remove admin' : 'Make admin' ?></button>
      <?php endif ?>
      <?php if ($u['status'] === 'deactivated'): ?>
        <button name="action" value="reactivate" class="link">Reactivate</button>
      <?php else: ?>
        <button name="action" value="deactivate" class="link danger"
          data-confirm="Deactivate <?= h($u['display_name']) ?>? They’ll be signed out and can’t sign in again until reactivated.">Deactivate</button>
      <?php endif ?>
    </form>
    <?php endif ?>
  </li>
  <?php endforeach ?>
  <?php if (!$users): ?><li class="muted">No one here.</li><?php endif ?>
</ul>

<h2 class="section">Recent activity</h2>
<ul class="list compact">
  <?php foreach ($events as $e): ?>
  <li>
    <div class="grow">
      <strong><?= h($e['display_name'] ?? ($e['telegram_id'] ? 'Telegram user ' . $e['telegram_id'] : 'Unknown')) ?></strong>
      <?= h($event_labels[$e['kind']] ?? $e['kind']) ?>
      <?php if ($e['detail'] !== ''): ?><span class="muted">: <?= h($e['detail']) ?></span><?php endif ?>
      <div class="small muted"><?= h($e['created_at']) ?> UTC</div>
    </div>
  </li>
  <?php endforeach ?>
  <?php if (!$events): ?><li class="muted">Nothing yet.</li><?php endif ?>
</ul>
<script src="<?= asset('confirm.js') ?>"></script>
<?php page_end();
