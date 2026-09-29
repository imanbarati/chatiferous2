<?php
// Admin: the daily reading. On/off, what's coming up, recent posts, and
// replacing the schedule from a spreadsheet (CSV).
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/reading.php';

$me = require_admin();
if (!config('daily_reading')) {
    redirect('');   // the feature is off in config.php
}
$msg = null;
$problems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle') {
        $on = setting('daily_reading_enabled') === '1';
        set_setting('daily_reading_enabled', $on ? '0' : '1');
        $msg = $on ? 'The daily reading is now off.' : 'The daily reading is now on.';
    } elseif ($action === 'post_now') {
        try {
            $status = post_daily_reading(gmdate('Y-m-d'), true);
            $msg = str_starts_with($status, 'posted') ? 'Today’s reading and poll have been posted.' : ucfirst($status) . '.';
        } catch (ActionError $e) {
            $msg = 'Couldn’t post: ' . $e->getMessage();
        }
    } elseif ($action === 'settings') {
        $t = (int)($_POST['topic'] ?? 0);
        $when = (string)($_POST['time'] ?? '');
        $name = trim((string)($_POST['poster'] ?? ''));
        if (!q("SELECT 1 FROM topics WHERE id = ? AND kind = 'topic'", [$t])->fetch()) {
            $msg = 'Please choose a topic.';
        } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $when)) {
            $msg = 'Please give the time as HH:MM (24-hour, UTC).';
        } elseif ($name === '' || mb_strlen($name) > 64) {
            $msg = 'Please give the poster a name (up to 64 characters).';
        } else {
            set_setting('daily_reading_topic', (string)$t);
            set_setting('daily_reading_time_utc', $when);
            $poster = q("SELECT id FROM users WHERE id = ? AND role = 'system'", [(int)setting('daily_reading_user')])->fetchColumn();
            if ($poster) {
                q('UPDATE users SET display_name = ? WHERE id = ?', [$name, $poster]);
            } else {
                q("INSERT INTO users (display_name, role, status) VALUES (?, 'system', 'active')", [$name]);
                set_setting('daily_reading_user', (string)db()->lastInsertId());
            }
            $msg = 'Settings saved.';
        }
    } elseif ($action === 'upload' && is_uploaded_file($_FILES['csv']['tmp_name'] ?? '')) {
        [$rows, $problems] = parse_schedule_csv($_FILES['csv']['tmp_name']);
        if ($rows) {
            replace_schedule($rows);
            $msg = count($rows) . ' readings loaded, ' . date('M j, Y', strtotime($rows[0][0])) . ' to ' . date('M j, Y', strtotime(end($rows)[0])) . '.';
        } else {
            $msg = 'Nothing was loaded; the schedule is unchanged.';
        }
    }
}

$enabled = setting('daily_reading_enabled') === '1';
$time = setting('daily_reading_time_utc', '05:00');
$topic = q('SELECT title FROM topics WHERE id = ?', [(int)setting('daily_reading_topic')])->fetchColumn();
$poster = q("SELECT display_name FROM users WHERE id = ? AND role = 'system'", [(int)setting('daily_reading_user')])->fetchColumn();
$topics = q("SELECT id, title FROM topics WHERE kind = 'topic' ORDER BY is_general DESC, title")->fetchAll();
$today = gmdate('Y-m-d');
$upcoming = [];
for ($i = 0; $i < 14; $i++) {
    $d = gmdate('Y-m-d', strtotime("$today +$i day"));
    $upcoming[$d] = readings_for($d);
}
$posted = q('SELECT * FROM reading_posts ORDER BY reading_date DESC LIMIT 7')->fetchAll();
$range = q('SELECT MIN(reading_date) a, MAX(reading_date) b, COUNT(*) n FROM reading_schedule')->fetch();
$today_posted = (bool)q('SELECT 1 FROM reading_posts WHERE reading_date = ?', [$today])->fetch();

page_start('Daily reading', ['back' => '', 'heading' => 'Daily reading', 'sub' => $enabled ? 'On' : 'Off']);
?>
<?php notice($msg) ?>
<?php if ($problems): ?>
  <div class="notice error"><strong>Some lines were skipped:</strong><br><?= implode('<br>', array_map('h', array_slice($problems, 0, 20))) ?></div>
<?php endif ?>

<div class="card">
  <p>Each day at <strong><?= h($time) ?> UTC</strong>, the reading
  and a <strong>Finished?</strong> poll (☑️ not started · 📖 started · ✅ finished) are posted in
  <strong><?= h($topic ?: 'no topic set') ?></strong> by <strong><?= h($poster ?: 'no one yet') ?></strong>.</p>
  <?php $last = setting('daily_reading_last_check'); ?>
  <p class="small muted"><?= $last ? 'Timer last checked ' . h(substr($last, 0, 16)) . ' UTC' . (time() - strtotime($last . ' UTC') > 3600 ? ' (over an hour ago: the timer may have stopped)' : '') . '.' : 'The timer hasn’t run yet.' ?></p>
  <form method="post" class="row-buttons left">
    <?= csrf_field() ?>
    <button name="action" value="toggle" class="primary pad"><?= $enabled ? 'Turn off' : 'Turn on' ?></button>
    <?php if ($enabled && !$today_posted && $upcoming[$today]): ?>
      <button name="action" value="post_now" class="link" data-confirm="Post today’s reading and poll now?">Post today’s now</button>
    <?php endif ?>
  </form>
</div>

<details class="card">
  <summary><strong>Settings</strong> <span class="muted small">(topic, time, who posts)</span></summary>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">
    <label>Topic
      <select name="topic" required>
        <option value="">Choose…</option>
        <?php foreach ($topics as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$t['id'] === (int)setting('daily_reading_topic') ? ' selected' : '' ?>><?= h($t['title']) ?></option><?php endforeach ?>
      </select>
    </label>
    <label>Time (24-hour, UTC)
      <input name="time" required pattern="([01][0-9]|2[0-3]):[0-5][0-9]" value="<?= h($time) ?>">
    </label>
    <label>Posted by (a system account with this name)
      <input name="poster" required maxlength="64" value="<?= h($poster ?: 'Daily Reading') ?>">
    </label>
    <button class="primary">Save</button>
  </form>
</details>

<h2 class="section">Coming up</h2>
<ul class="list compact">
  <?php foreach ($upcoming as $d => $readings): ?>
  <li>
    <div class="grow">
      <strong><?= h(date('D., M j', strtotime("$d 12:00 UTC"))) ?></strong>
      <?php if ($d === $today): ?><span class="muted"> · today<?= $today_posted ? ', posted' : '' ?></span><?php endif ?>
      <div class="<?= $readings ? '' : 'notice error' ?>"><?= $readings ? nl2br(h(implode("\n", $readings))) : 'Nothing scheduled: no reading will be posted.' ?></div>
    </div>
  </li>
  <?php endforeach ?>
</ul>

<h2 class="section">Recently posted</h2>
<ul class="list compact">
  <?php foreach ($posted as $p): ?>
    <li><div class="grow"><?= h(date('D., M j, Y', strtotime($p['reading_date'] . ' 12:00 UTC'))) ?>
      <span class="muted"> · posted <?= h(substr($p['posted_at'], 0, 16)) ?> UTC</span></div></li>
  <?php endforeach ?>
  <?php if (!$posted): ?><li class="muted">Nothing yet.</li><?php endif ?>
</ul>

<h2 class="section">Schedule</h2>
<div class="card">
  <p class="small muted"><?= $range['n'] ? (int)$range['n'] . ' readings, ' . h(date('M j, Y', strtotime($range['a']))) . ' to ' . h(date('M j, Y', strtotime($range['b']))) : 'No schedule loaded.' ?></p>
  <p class="small">To replace the whole schedule, upload a spreadsheet saved as CSV with the columns
  <code>date</code> (for example <code>19-Sep-26</code>) and <code>readings_merged</code>. Days can have several rows.</p>
  <form method="post" enctype="multipart/form-data" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <button class="primary" data-confirm="Replace the entire reading schedule with this file?">Upload and replace</button>
  </form>
</div>
<script src="<?= asset('confirm.js') ?>"></script>
<?php page_end();
