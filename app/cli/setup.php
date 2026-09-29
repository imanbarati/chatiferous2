<?php
// First-time setup, after cli/migrate.php: creates the owner account (you) and the
// General topic. Run once: php cli/setup.php
// Afterwards, invite people from the app's menu (Invite someone).
require __DIR__ . '/../lib/bootstrap.php';

if (q("SELECT 1 FROM users WHERE role = 'owner'")->fetch()) {
    exit("There's already an owner account. Nothing to do.\n");
}

function ask(string $prompt, bool $hidden = false): string
{
    echo $prompt;
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
        $v = trim((string)fgets(STDIN));
        system('stty echo');
        echo "\n";
        return $v;
    }
    return trim((string)fgets(STDIN));
}

$name = ask('Your name, as the group will see it: ');
$username = ask('Username (for signing in): ');
$password = ask('Password (at least 8 characters): ', true);
if ($name === '' || mb_strlen($name) > 64) {
    exit("Please give a name of up to 64 characters.\n");
}
if (!valid_username($username)) {
    exit("Usernames are 3–32 characters: letters, numbers and underscores, starting with a letter.\n");
}
if (strlen($password) < 8 || $password !== ask('Type it again: ', true)) {
    exit("The password must be at least 8 characters, typed the same twice.\n");
}

q("INSERT INTO users (display_name, username, password_hash, role, status, color_index, claimed_at)
   VALUES (?, ?, ?, 'owner', 'active', ?, UTC_TIMESTAMP())",
  [$name, $username, password_hash($password, PASSWORD_DEFAULT), random_int(0, 6)]);
$owner = (int)db()->lastInsertId();

if (!q("SELECT 1 FROM topics WHERE is_general = 1")->fetch()) {
    q("INSERT INTO topics (title, is_general, created_by) VALUES ('General', 1, ?)", [$owner]);
}
echo "Done. Sign in at " . config('base_url') . " as $username.\n";
