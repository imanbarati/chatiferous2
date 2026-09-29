<?php
// "Log in with Telegram" (the Login Widget) and the membership check.
// https://core.telegram.org/widgets/login-legacy

const TELEGRAM_LOGIN_MAX_AGE = 60 * 10; // a sign-in link is good for 10 minutes, once

// "Log in with Telegram" is optional: it's on only when the bot and group are configured.
function telegram_login_enabled(): bool
{
    return (string)config('telegram_bot_username') !== '' && (string)config('telegram_bot_token') !== ''
        && (int)config('telegram_group_id') !== 0;
}

// Returns the verified login fields, or null if the signature is wrong or stale.
function telegram_verify_login(array $query): ?array
{
    $fields = ['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date'];
    if (!telegram_login_enabled()) {
        return null;   // without a bot token the signature key would be public
    }
    $hash = $query['hash'] ?? '';
    $data = [];
    foreach ($query as $k => $v) {
        if ($k !== 'hash' && is_string($v) && (in_array($k, $fields, true) || $k === 'lang')) {
            $data[$k] = $v;
        }
    }
    if (!is_string($hash) || $hash === '' || empty($data['id']) || empty($data['auth_date'])) {
        return null;
    }
    ksort($data);
    $check = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($data), $data));
    $secret = hash('sha256', config('telegram_bot_token'), true);
    if (!hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
        return null;
    }
    if (time() - (int)$data['auth_date'] > TELEGRAM_LOGIN_MAX_AGE) {
        return null;
    }
    return $data;
}

function telegram_api(string $method, array $params = []): ?array
{
    $ch = curl_init('https://api.telegram.org/bot' . config('telegram_bot_token') . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return is_array($json) ? $json : null;
}

// true = in the group, false = not in it, null = Telegram didn't answer.
function telegram_is_group_member(int $telegram_id): ?bool
{
    $r = telegram_api('getChatMember', ['chat_id' => config('telegram_group_id'), 'user_id' => $telegram_id]);
    if ($r === null) {
        return null;
    }
    if (empty($r['ok'])) {
        // "member not found" and similar: not in the group.
        return ($r['error_code'] ?? 0) === 400 ? false : null;
    }
    $m = $r['result'];
    return in_array($m['status'], ['creator', 'administrator', 'member'], true)
        || ($m['status'] === 'restricted' && !empty($m['is_member']));
}
