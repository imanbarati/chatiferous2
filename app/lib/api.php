<?php
// Shared by the JSON endpoints in web/api/.

require_once APP_DIR . '/lib/chat.php';
require_once APP_DIR . '/lib/actions.php';

// For endpoints that change something: POST, CSRF, signed in. Returns the user.
function api_post(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['error' => 'POST only.'], 405);
    }
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        json_out(['error' => 'This page is out of date. Please reload it.'], 400);
    }
    return api_user();
}

// Runs an action, turning its ActionError into a friendly JSON error.
function api_run(callable $fn): never
{
    try {
        json_out($fn() ?? ['ok' => true]);
    } catch (ActionError $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        json_out(['error' => $e->getMessage()], 400);
    }
}

// One message, ready for the client.
function message_json(int $id, int $me): array
{
    $users = [];
    $rows = q('SELECT * FROM messages WHERE id = ?', [$id])->fetchAll();
    $m = hydrate_messages($rows, $me, $users)[0];
    return ['message' => $m, 'users' => user_map($users)];
}

function post_mentions(): array
{
    $list = json_decode((string)($_POST['mentions'] ?? '[]'), true);
    return is_array($list) ? array_values(array_filter($list, fn($m) => isset($m['user_id'], $m['name']) && is_string($m['name']))) : [];
}
