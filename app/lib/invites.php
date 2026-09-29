<?php
// Invite links (new people, no Telegram needed) and claim links (an existing,
// imported member who can't use Telegram login takes over their account).

const MEMBER_INVITES_PER_WEEK = 5;

// How many more invites a non-admin may create this week (null = unlimited).
function invites_left(array $user): ?int
{
    if (is_admin($user)) {
        return null;
    }
    // Each member link admits one person. A link counts if someone joined with it or it
    // can still be used; one that expired or was revoked unused gives its slot back.
    $used = (int)q('SELECT COUNT(*) FROM invite_codes WHERE created_by = ? AND user_id IS NULL AND created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY
                    AND (uses > 0 OR (revoked_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())))',
        [$user['id']])->fetchColumn();
    return max(0, MEMBER_INVITES_PER_WEEK - $used);
}

function new_invite(array $admin, ?int $user_id, string $note, int $max_uses, int $days): string
{
    // Members: single-use links, 14 days, 5 a week. Admins: anything.
    if (!is_admin($admin)) {
        if ($user_id !== null || invites_left($admin) < 1) {
            fail('You’ve used your ' . MEMBER_INVITES_PER_WEEK . ' invites for this week. More become available 7 days after each one.');
        }
        [$max_uses, $days] = [1, 14];
    }
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $code = '';
    for ($i = 0; $i < 16; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    q('INSERT INTO invite_codes (code, user_id, note, max_uses, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)', [
        $code, $user_id, mb_substr(trim($note), 0, 200), max(1, min(500, $max_uses)),
        $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null, $admin['id'],
    ]);
    return $code;
}

function invite_link(string $code): string
{
    return 'https://' . $_SERVER['HTTP_HOST'] . url('join.php?c=' . $code);
}

// The invite if it can still be used, else null.
function usable_invite(string $code): ?array
{
    if (!preg_match('/^[a-z0-9]{16}$/', $code)) {
        return null;
    }
    $inv = q('SELECT * FROM invite_codes WHERE code = ? AND revoked_at IS NULL AND uses < max_uses
              AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())', [$code])->fetch();
    if (!$inv) {
        return null;
    }
    if ($inv['user_id']) {
        $u = q('SELECT status FROM users WHERE id = ?', [$inv['user_id']])->fetchColumn();
        if (!in_array($u, ['unclaimed', 'active'], true)) {
            return null;
        }
    }
    return $inv;
}

// Uses an invite: creates the account (or completes the claimed one). Returns the user.
function redeem_invite(array $inv, string $display, string $username, string $password): array
{
    if ($display === '' || mb_strlen($display) > 64) {
        fail('Please enter your name (up to 64 characters).');
    }
    if (!valid_username($username)) {
        fail('Usernames are 3–32 characters: letters, numbers and underscores, starting with a letter.');
    }
    if (q('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, (int)$inv['user_id']])->fetch()) {
        fail('That username is taken. Please choose another.');
    }
    if (strlen($password) < 8) {
        fail('Please choose a password of at least 8 characters.');
    }
    db()->beginTransaction();
    // Count the use first, so a link can't be used more times than allowed.
    if (q('UPDATE invite_codes SET uses = uses + 1 WHERE id = ? AND uses < max_uses AND revoked_at IS NULL', [$inv['id']])->rowCount() !== 1) {
        db()->rollBack();
        fail('This invite link has already been used up.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($inv['user_id']) {
        // A new password from a claim/reset link also signs the account out everywhere else.
        q("UPDATE users SET display_name = ?, username = ?, password_hash = ?, status = 'active',
           claimed_at = COALESCE(claimed_at, UTC_TIMESTAMP()), invite_id = ?, session_epoch = session_epoch + 1 WHERE id = ?",
            [$display, $username, $hash, $inv['id'], $inv['user_id']]);
        $id = (int)$inv['user_id'];
        $kind = 'claim';
    } else {
        q("INSERT INTO users (display_name, username, password_hash, role, status, color_index, claimed_at, invite_id)
           VALUES (?, ?, ?, 'member', 'active', ?, UTC_TIMESTAMP(), ?)", [$display, $username, $hash, random_int(0, 6), $inv['id']]);
        $id = (int)db()->lastInsertId();
        $kind = 'join';
    }
    db()->commit();
    $inviter = q('SELECT id, display_name, role FROM users WHERE id = ?', [(int)$inv['created_by']])->fetch();
    auth_event($kind, $id, null, 'invite link' . ($inviter ? ' from ' . $inviter['display_name'] : '') . ($inv['note'] !== '' ? ': ' . $inv['note'] : ''));
    // Tell the owner when a member's (non-admin's) invite brings someone in.
    if ($kind === 'join' && $inviter && !in_array($inviter['role'], ['owner', 'admin'], true)) {
        try {
            require_once APP_DIR . '/lib/push.php';
            $owners = q("SELECT s.* FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE u.role = 'owner'")->fetchAll();
            push_deliver(array_map(fn($d) => [$d, [
                'title' => 'New member: ' . $display,
                'body'  => 'Joined with an invite from ' . $inviter['display_name'] . '.',
                'url'   => url('admin/members.php'),
                'tag'   => 'invite-' . $inv['id'],
                'icon'  => url(config('app_icons') . 'icon-192.png'),
            ]], $owners));
        } catch (Throwable $e) {
            error_log('invite alert failed: ' . $e->getMessage());   // never block someone joining
        }
    }
    if ($kind === 'join') {
        mark_all_read($id);
    }
    return q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
}
