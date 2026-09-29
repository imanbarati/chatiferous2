<?php
// Web Push: telling members' phones and computers about new messages.
// cli/push_send.php runs this every minute from cron.

require_once APP_DIR . '/vendor/autoload.php';
require_once APP_DIR . '/lib/chat.php';
require_once APP_DIR . '/lib/reading.php';   // setting()

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

const PUSH_ACTIVE_SECONDS = 60;   // someone reading a topic right now isn't notified about it

function web_push(): WebPush
{
    $wp = new WebPush(['VAPID' => [
        'subject'    => config('vapid_subject'),
        'publicKey'  => config('vapid_public'),
        'privateKey' => config('vapid_private'),
    ]], ['TTL' => 86400, 'urgency' => 'normal']);
    $wp->setReuseVAPIDHeaders(true);
    return $wp;
}

// Browsers' push services. The server only ever sends to these, so a made-up "subscription"
// can't point it at other machines.
const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
    '.push.apple.com', '.notify.windows.com'];

function push_endpoint_ok(string $endpoint): bool
{
    $u = parse_url($endpoint);
    if (($u['scheme'] ?? '') !== 'https' || isset($u['port']) || isset($u['user']) || empty($u['host'])) {
        return false;
    }
    $host = strtolower($u['host']);
    foreach (PUSH_HOSTS as $h) {
        if ($h[0] === '.' ? str_ends_with($host, $h) : $host === $h) {
            return true;
        }
    }
    return false;
}

function save_subscription(int $user_id, array $s, string $device): void
{
    $endpoint = (string)($s['endpoint'] ?? '');
    $keys = $s['keys'] ?? [];
    if (empty($keys['p256dh']) || empty($keys['auth'])) {
        fail('That notification subscription looks incomplete.');
    }
    if (!push_endpoint_ok($endpoint)) {
        fail('That browser’s notification service isn’t supported.');
    }
    q('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, encoding, device) VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), device = VALUES(device)', [
        $user_id, $endpoint, hash('sha256', $endpoint), (string)$keys['p256dh'], (string)$keys['auth'],
        in_array($s['encoding'] ?? '', ['aes128gcm', 'aesgcm'], true) ? $s['encoding'] : 'aes128gcm', mb_substr($device, 0, 120),
    ]);
}

function remove_subscription(int $user_id, string $endpoint): void
{
    q('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$user_id, hash('sha256', $endpoint)]);
}

// Sends $payloads (subscription row => payload array) and cleans up dead subscriptions.
// Returns [sent, failed].
function push_deliver(array $items): array
{
    if (!$items) {
        return [0, 0];
    }
    $wp = web_push();
    $byEndpoint = [];
    foreach ($items as [$sub, $payload]) {
        if (!push_endpoint_ok($sub['endpoint'])) {
            continue;
        }
        $byEndpoint[$sub['endpoint']] = $sub;
        $wp->queueNotification(Subscription::create([
            'endpoint' => $sub['endpoint'], 'contentEncoding' => $sub['encoding'],
            'keys' => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth']],
        ]), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), isset($payload['tag']) ? ['topic' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', $payload['tag']), 0, 32)] : []);
    }
    $sent = $failed = 0;
    foreach ($wp->flush() as $report) {
        $sub = $byEndpoint[$report->getEndpoint()] ?? null;
        if ($report->isSuccess()) {
            $sent++;
            if ($sub) {
                q('UPDATE push_subscriptions SET last_success_at = UTC_TIMESTAMP() WHERE id = ?', [$sub['id']]);
            }
        } else {
            $failed++;
            if ($sub && $report->isSubscriptionExpired()) {
                q('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);   // uninstalled or turned off
            }
            error_log('push failed: ' . $report->getReason());
        }
    }
    return [$sent, $failed];
}

// New messages since the last run -> one notification per member per topic
// ("3 new in Romans"), respecting mutes, "mentions only", and what they've read.
function push_send_pending(): string
{
    $last = (int)setting('push_last_message_id', '0');
    $msgs = q("SELECT m.id, m.topic_id, m.user_id, m.kind, m.text, m.entities, m.service,
                 u.display_name, t.title, t.kind AS topic_kind, t.dm_a, t.dm_b,
                 (SELECT p.question FROM polls p WHERE p.message_id = m.id) AS poll,
                 (SELECT a.kind FROM attachments a WHERE a.message_id = m.id LIMIT 1) AS att,
                 (SELECT a.name FROM attachments a WHERE a.message_id = m.id LIMIT 1) AS att_name
               FROM messages m JOIN users u ON u.id = m.user_id JOIN topics t ON t.id = m.topic_id
               WHERE m.id > ? AND m.deleted_at IS NULL AND m.kind IN ('text', 'poll')
                 AND m.telegram_id IS NULL   -- brought over from Telegram: old news, never notify
               ORDER BY m.id LIMIT 500", [$last])->fetchAll();
    if (!$msgs) {
        return 'nothing new';
    }
    $max = (int)end($msgs)['id'];
    // The site's own automated tests post (and delete) clearly-marked messages: never notify about them.
    $msgs = array_values(array_filter($msgs, fn($m) => !str_starts_with((string)($m['poll'] ?? $m['text']), '[Automated test')));
    $subs = [];
    foreach (q("SELECT s.*, u.notify_level, u.last_active_at, u.active_topic_id FROM push_subscriptions s
                JOIN users u ON u.id = s.user_id WHERE u.status = 'active' AND u.notify_level <> 'off'")->fetchAll() as $s) {
        $subs[(int)$s['user_id']][] = $s;
    }
    $items = [];
    foreach ($subs as $uid => $devices) {
        $level = $devices[0]['notify_level'];
        $active = $devices[0]['last_active_at'] && time() - strtotime($devices[0]['last_active_at'] . ' UTC') < PUSH_ACTIVE_SECONDS;
        $state = q('SELECT topic_id, last_read_id, muted FROM read_state WHERE user_id = ?', [$uid])->fetchAll(PDO::FETCH_UNIQUE);
        $mentioned = $level === 'mentions'
            ? array_flip(q('SELECT message_id FROM mentions WHERE user_id = ? AND message_id > ?', [$uid, $last])->fetchAll(PDO::FETCH_COLUMN))
            : [];
        $byTopic = [];
        foreach ($msgs as $m) {
            $tid = (int)$m['topic_id'];
            $dm = $m['topic_kind'] === 'dm';
            if ($dm && $uid !== (int)$m['dm_a'] && $uid !== (int)$m['dm_b']) {
                continue;   // someone else's direct message
            }
            if ((int)$m['user_id'] === $uid || (int)$m['id'] <= (int)($state[$tid]['last_read_id'] ?? 0)) {
                continue;
            }
            // A direct message is addressed to you, so it counts even at "only mentions".
            if (!empty($state[$tid]['muted']) || ($level === 'mentions' && !$dm && !isset($mentioned[$m['id']]))) {
                continue;
            }
            if ($active && (int)$devices[0]['active_topic_id'] === $tid) {
                continue;   // they're looking at it
            }
            $byTopic[$tid][] = $m;
        }
        if (!$byTopic) {
            continue;
        }
        $unread = (int)q("SELECT COUNT(*) FROM messages m JOIN topics t ON t.id = m.topic_id
                          LEFT JOIN read_state r ON r.topic_id = m.topic_id AND r.user_id = ?
                          WHERE (t.kind = 'topic' OR t.dm_a = ? OR t.dm_b = ?)
                            AND m.id > COALESCE(r.last_read_id, 0) AND m.deleted_at IS NULL AND m.user_id <> ? AND COALESCE(r.muted, 0) = 0",
            [$uid, $uid, $uid, $uid])->fetchColumn();
        foreach ($byTopic as $tid => $list) {
            $m = end($list);
            $first = (int)$list[0]['id'];
            $n = count($list);
            $dm = $m['topic_kind'] === 'dm';
            // A DM is sealed: the server can't preview it. The phone opens it with its own key
            // (the sealed text and the recipient's locked conversation key ride along).
            $preview = $dm ? 'New message' : message_preview($m['kind'], mb_substr((string)$m['text'], 0, 200), $m['poll'], $m['att'], $m['att_name'], $m['service'], $m['display_name']);
            $payload = [
                'title'  => $dm ? $m['display_name'] : ($n === 1 ? $m['display_name'] . ' in ' . $m['title'] : $m['title']),
                'body'   => $n === 1 ? mb_substr($preview, 0, 180) : "$n new messages" . ($dm ? ': ' : ' · ' . $m['display_name'] . ': ') . mb_substr($preview, 0, 140),
                'url'    => url('t/' . $tid . '/' . $first),   // opens at the first new message
                'tag'    => 'topic-' . $tid,
                'icon'   => url(config('app_icons') . 'icon-192.png'),
                'unread' => $unread,
            ];
            if ($dm && $n === 1 && is_sealed((string)$m['text']) && strlen($m['text']) < 2800) {
                $lock = q('SELECT locked FROM dm_keys WHERE topic_id = ? AND user_id = ?', [$tid, $uid])->fetchColumn();
                if ($lock) {
                    $payload['sealed'] = $m['text'];
                    $payload['lock'] = $lock;
                }
            }
            foreach ($devices as $d) {
                $items[] = [$d, $payload];
            }
        }
    }
    [$sent, $failed] = push_deliver($items);
    set_setting('push_last_message_id', (string)$max);
    return "messages up to $max; sent $sent, failed $failed";
}

function push_test(int $user_id): array
{
    $devices = q('SELECT * FROM push_subscriptions WHERE user_id = ?', [$user_id])->fetchAll();
    return push_deliver(array_map(fn($d) => [$d, [
        'title' => config('group_name'),
        'body'  => 'Notifications are working on this device. 🎉',
        'url'   => url(),
        'tag'   => 'test',
        'icon'  => url(config('app_icons') . 'icon-192.png'),
    ]], $devices));
}
