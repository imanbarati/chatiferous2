<?php
// End-to-end keys for direct messages. Everything here is either public or locked; the server
// can't open any of it.
//   GET                      your keys: public key, version, and the two locked copies
//   GET user=<id>            someone's public key (null if they haven't set up private messages)
//   POST setup: public_key, locked_pw, locked_code [, fresh=1 to start over]
//   POST relock: locked_pw and/or locked_code (new password / new recovery code)
//   POST dm_key: topic, for (user id), locked   (a conversation's key, locked for that member)
//   POST check: password   (is it yours? before a key is locked with it)
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = api_user();
    if (isset($_GET['user'])) {
        json_out(['key' => public_key((int)$_GET['user'])]);
    }
    $k = my_keys((int)$user['id']);
    json_out(['keys' => $k ? ['version' => (int)$k['version'], 'public_key' => $k['public_key'],
        'locked_pw' => $k['locked_pw'], 'locked_code' => $k['locked_code']] : null]);
}
$user = api_post();
api_run(function () use ($user) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'setup') {
        $k = save_keys($user, (string)($_POST['public_key'] ?? ''), (string)($_POST['locked_pw'] ?? ''),
            (string)($_POST['locked_code'] ?? ''), !empty($_POST['fresh']));
        return ['version' => (int)$k['version']];
    }
    if ($action === 'relock') {
        relock_keys($user, isset($_POST['locked_pw']) ? (string)$_POST['locked_pw'] : null,
            isset($_POST['locked_code']) ? (string)$_POST['locked_code'] : null);
        return ['ok' => true];
    }
    if ($action === 'check') {   // "is this my password?" (the site sees it at every sign-in anyway)
        return ['ok' => password_verify((string)($_POST['password'] ?? ''), (string)$user['password_hash'])];
    }
    if ($action === 'dm_key') {
        save_dm_key($user, (int)($_POST['topic'] ?? 0), (int)($_POST['for'] ?? 0), (string)($_POST['locked'] ?? ''));
        return ['ok' => true];
    }
    fail('Unknown request.');
});
