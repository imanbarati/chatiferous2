<?php
// Server-side tests. They run against a separate, throwaway database
// (config.test.php), which they wipe and rebuild each time.
// Run with tests/run_server_tests.sh.

// The code under test is the tests' own copy (~/chatiferous-tests/app), never the live site's.
putenv('CHATIFEROUS_CONFIG=' . __DIR__ . '/app/config.test.php');
require __DIR__ . '/app/lib/bootstrap.php';
require APP_DIR . '/lib/chat.php';
require APP_DIR . '/lib/actions.php';
require APP_DIR . '/lib/reading.php';
require APP_DIR . '/lib/invites.php';
require APP_DIR . '/lib/topics.php';

if (config('test_database') !== true) {
    exit("Refusing to run: config.test.php must say 'test_database' => true (the tests wipe that database).\n");
}

$passed = 0;
$failed = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed[] = $name . ($detail !== '' ? " ($detail)" : '');
}
function throws(callable $fn, string $contains = ''): bool
{
    try {
        $fn();
    } catch (ActionError $e) {
        return $contains === '' || str_contains($e->getMessage(), $contains);
    }
    return false;
}
// Entities as readable "type=text" strings.
function ents(string $text, array $ents): array
{
    $u16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    return array_map(fn($e) => $e['type'] . '=' . mb_convert_encoding(substr($u16, $e['offset'] * 2, $e['length'] * 2), 'UTF-8', 'UTF-16LE')
        . (isset($e['url']) ? '->' . $e['url'] : ''), $ents);
}

// ---- Fresh database ----
db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    db()->exec("DROP TABLE `$t`");
}
db()->exec('SET FOREIGN_KEY_CHECKS = 1');
db()->exec('CREATE TABLE migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
foreach (glob(APP_DIR . '/sql/[0-9][0-9][0-9]_*.sql') as $f) {
    db()->exec(file_get_contents($f));
}

// ============ Visitor address ============
check('ip: inside a Cloudflare v4 range', ip_in_ranges('104.16.1.2', CLOUDFLARE_RANGES));
check('ip: edge of a /20', ip_in_ranges('173.245.63.255', CLOUDFLARE_RANGES) && !ip_in_ranges('173.245.64.0', CLOUDFLARE_RANGES));
check('ip: inside a Cloudflare v6 range', ip_in_ranges('2606:4700:10::1', CLOUDFLARE_RANGES));
check('ip: ordinary addresses are not Cloudflare', !ip_in_ranges('8.8.8.8', CLOUDFLARE_RANGES) && !ip_in_ranges('2001:db8::1', CLOUDFLARE_RANGES));
check('ip: junk is not Cloudflare', !ip_in_ranges('nonsense', CLOUDFLARE_RANGES));
$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
check('ip: a forged header from outside Cloudflare is ignored', client_ip() === '203.0.113.9');
$_SERVER['REMOTE_ADDR'] = '162.158.1.1';
check('ip: the header is believed from Cloudflare', client_ip() === '1.2.3.4');
unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP']);

// ============ Push endpoints ============
require_once APP_DIR . '/lib/push.php';
check('push: known services allowed', push_endpoint_ok('https://fcm.googleapis.com/fcm/send/abc') && push_endpoint_ok('https://web.push.apple.com/QX') && push_endpoint_ok('https://wns2-bl2p.notify.windows.com/w/?token=x'));
check('push: other hosts refused', !push_endpoint_ok('https://10.0.0.5/x') && !push_endpoint_ok('https://evil.example/fcm.googleapis.com') && !push_endpoint_ok('https://fcm.googleapis.com.evil.example/x'));
check('push: ports, users and http refused', !push_endpoint_ok('https://fcm.googleapis.com:2087/x') && !push_endpoint_ok('https://a@fcm.googleapis.com/x') && !push_endpoint_ok('http://fcm.googleapis.com/x'));

// ============ Bible text (USFM parsing) ============
require_once APP_DIR . '/lib/usfm.php';
$usfm = "\\id JHN\n\\h John\n\\toc3 Jhn\n\\c 3\n\\s1 A heading\n\\p\n"
    . "\\v 16 For God so \\w loved|strong=\"G25\"\\w* the world, that he gave his only born\\f + \\fr 3.16 \\ft The phrase is from \\fq μονογενους\\fq*.\\f* Son,\n"
    . "\\q1 \\v 17 \\wj I didn\u{2019}t come to judge\\wj*,\\x - \\xo 3.17 \\xt Luke 9:56\\x*\n"
    . "\\q2 but to save.\n";
$parsed = usfm_parse_book($usfm);
$ch = $parsed['chapters'][3] ?? ['html' => '', 'verses' => [], 'notes' => []];
check('usfm: book name and abbreviation', $parsed['code'] === 'JHN' && $parsed['name'] === 'John' && $parsed['abbrev'] === 'Jhn');
check('usfm: verse text, Strong\'s numbers stripped', str_contains($ch['verses'][16] ?? '', 'so loved the world'));
check('usfm: note markers are not part of the verse text', !str_contains($ch['verses'][16] ?? '', 'born a') && str_contains($ch['verses'][16] ?? '', 'only born Son'));
check('usfm: footnote kept with its body', ($ch['notes'][0]['kind'] ?? '') === 'f'
    && str_contains($ch['notes'][0]['body'] ?? '', 'The phrase is from') && !str_contains($ch['notes'][0]['body'] ?? '', '3.16'));
check('usfm: cross-reference keeps its references', ($ch['notes'][1]['kind'] ?? '') === 'x' && ($ch['notes'][1]['refs'] ?? '') === 'Luke 9:56');
check('usfm: heading, paragraph and poetry classes', str_contains($ch['html'], '<h3 class="b-s">A heading</h3>')
    && str_contains($ch['html'], '<p class="b-p">') && str_contains($ch['html'], '<p class="b-q1">') && str_contains($ch['html'], '<p class="b-q2">'));
check('usfm: verse numbers and note markers hidden from screen readers',
    substr_count($ch['html'], 'aria-hidden="true"') === 4);
check('usfm: words of Jesus marked', str_contains($ch['html'], "<span class=\"wj\">I didn\u{2019}t come to judge</span>"));
check('usfm: a verse carried into the next paragraph reopens without a number',
    str_contains($ch['html'], '<p class="b-q2"><span class="v" data-v="17">but to save.'));
check('usfm: no empty verse spans', !preg_match('/data-v="\\d+"><\\/span>/', $ch['html']));
check('usfm: note letters run a, b, c', usfm_letter(0) === 'a' && usfm_letter(25) === 'z' && usfm_letter(26) === 'aa');

// ============ Single-marker italic (plain Markdown) ============
$fmt = function (string $in) { [$t, $e] = array_values(compose_message($in)); return [$t, implode(',', array_map(fn($x) => $x['type'], $e))]; };
check('italic: *star*', $fmt('*star*') === ['star', 'italic']);
check('italic: _under_', $fmt('_under_') === ['under', 'italic']);
check('italic: a phrase', $fmt('a *b c* d') === ['a b c d', 'italic']);
check('italic: __double__ still works', $fmt('__x__') === ['x', 'italic']);
check('italic: **bold** untouched', $fmt('**x**') === ['x', 'bold']);
check('italic: snake_case left alone', $fmt('snake_case_word here') === ['snake_case_word here', '']);
check('italic: arithmetic left alone', $fmt('2 * 3 * 4') === ['2 * 3 * 4', '']);
check('italic: inside a word left alone', $fmt('pre*fix*post') === ['pre*fix*post', '']);
check('italic: doesn\'t run past a line', $fmt("*x\ny*") === ["*x\ny*", '']);
check('italic: mixed with bold', $fmt('*a* and **b**') === ['a and b', 'italic,bold']);

// ============ Bible search ============
// (the throwaway database has no Bible text, so this checks the query building, not results)
require_once APP_DIR . '/lib/bible.php';
[$where, $args] = bible_scope_sql('nt');
check('search: New Testament scope lists 27 books', substr_count($where, '?') === 27 && count($args) === 27 && in_array('MAT', $args, true) && !in_array('GEN', $args, true));
[$where, $args] = bible_scope_sql('ot');
check('search: Old Testament scope lists 39 books', count($args) === 39 && in_array('MAL', $args, true));
[$where, $args] = bible_scope_sql('PSA');
check('search: one book', $where === ' AND book = ?' && $args === ['PSA']);
[$where, $args] = bible_scope_sql('nonsense');
check('search: an unknown scope searches everything', $where === '' && $args === []);
check('offline: no such book, nothing to download', bible_book_bundle('KJV', 'NOPE') === null);
$empty = bible_search('KJV', '   ');
check('search: nothing typed, nothing done', $empty['goto'] === null && $empty['hits'] === [] && $empty['total'] === 0);

// The query language: words are prefixes and all required, OR takes either, - excludes, and
// "quoted words" are confirmed in order.
[$b, $ph] = bible_boolean('faith works');
check('search: two words are both required', $b === '+faith* +works*' && $ph === []);
[$b, $ph] = bible_boolean('faith OR works');
check('search: OR takes either', $b === '(+faith*) (+works*)' && $ph === []);
[$b, $ph] = bible_boolean('faith -works');
check('search: a leading minus excludes', $b === '+faith* -works*');
[$b, $ph] = bible_boolean('"still small voice"');
check('search: a quoted phrase is kept whole', $b === '+"still small voice"' && $ph === ['still small voice']);
[$b] = bible_boolean('descen');
check('search: part of a word finds the rest of it', $b === '+descen*');
check('search: a list of books narrows the scope',
    bible_scope_sql(['PSA', 'JHN']) === [' AND book IN (?,?)', ['PSA', 'JHN']]);
check('search: the fetched translations are never searched',
    !array_intersect(bible_searchable_versions(), api_bible_versions()));
// Only where a key is configured is NASB a version at all; without one it is simply unknown,
// and either way searching it finds nothing.
$off = bible_search(['NASB'], 'shepherd');
check('search: asking for one of them finds nothing and says so',
    $off['hits'] === [] && $off['unsearchable'] === (api_bible_is('NASB') ? ['NASB'] : []));

// ============ Links inside the app ============
require_once APP_DIR . '/lib/compose.php';
require_once APP_DIR . '/lib/previews.php';
check('links: an ordinary link is kept', safe_url('https://example.com/x') === 'https://example.com/x');
check('links: a bare host gets https', safe_url('example.com/x') === 'https://example.com/x');
check('links: a path inside the app is allowed', safe_url(url('reading/2026-09-20')) === url('reading/2026-09-20'));
check('links: javascript is refused', safe_url('javascript:alert(1)') === null);
check('links: a path elsewhere on the host is refused', safe_url('/etc/passwd') === null);
check('links: a protocol-relative link is refused', safe_url('//evil.example/x') === null);
check('links: the app makes no preview card of itself', preview_normalize(url('reading/2026-09-20')) === null);
check('links: an outside link still gets a card', preview_normalize('https://example.com/') === 'https://example.com/');

// The day's reading points at the page built for that day — where there is a reader to point at.
require_once APP_DIR . '/lib/reading.php';
$day = reading_text('2026-09-20', ['2 Samuel 8:1-18 | Psalm 60']);
check('reading: the passages are there', str_contains($day, '2 Samuel 8:1-18'));
$link = str_contains($day, '[Read](' . url('reading/2026-09-20') . ')');
check('reading: a link to the day\'s own page, when the reader is switched on',
    $link === (bool)config('bible_reader'));

// ============ Commentaries ============
require_once APP_DIR . '/lib/commentary.php';
require_once APP_DIR . '/lib/commentary_works.php';
$md = "# Barnes — Romans 11\n\n*Barnes' Notes*\n\n### Verse 1\n\nI say then - an objection.\n\nSecond paragraph.\n\n### Verses 33–36\n\nVerses 33-36\n\nO the depth!\n";
$parsed = commentary_parse_markdown($md);
check('commentary: one verse and a range', array_map('strval', array_keys($parsed)) === ['1', '33-36']);
check('commentary: paragraphs become paragraphs', substr_count($parsed['1'], '<p>') === 2);
check('commentary: the repeated range line is dropped', !str_contains($parsed['33-36'], 'Verses 33-36'));
check('commentary: a range covers the verses inside it', commentary_range('33-36') === [33, 36]);
check('commentary: a single verse is its own range', commentary_range('7') === [7, 7]);
check('commentary: small capitals are put back together',
    commentary_clean_html('<p>G E N E S I S</p>') === '<p>GENESIS</p>');
check('commentary: two such words keep the space between them',
    commentary_clean_html("<p>S E C O N D \u{00A0} S A M U E L</p>") === '<p>SECOND SAMUEL</p>');
check('commentary: an ordinary capital is left alone',
    str_contains(commentary_html('W. R. Newell wrote'), 'W. R. Newell'));
check('commentary: markup in the text is escaped', str_contains(commentary_html('a <script>b</script> c'), '&lt;script&gt;'));
check('commentary: italics survive', str_contains(commentary_html('the *Greek* word'), '<em>Greek</em>'));
$verse = 'for god so loved the world that he gave his only begotten son that whosoever believeth in him should not perish but have everlasting life';
$opens = [16 => 'for god so loved the world'];
check('commentary: a verse set out in full is dropped',
    drop_reprinted_passage("<p>$verse</p><p>Who shall speak worthily of such a verse?</p>", $opens, [16 => $verse])
    === '<p>Who shall speak worthily of such a verse?</p>');
check('commentary: a lemma quoting the phrase expounded is kept',
    str_contains(drop_reprinted_passage('<p>For God so loved the world,.... The Persic version reads men.</p>', $opens, [16 => $verse]), 'Persic'));
check('commentary: a passage of several verses is dropped', drop_reprinted_passage(
    '<p>1 There was a man of the Pharisees 2 The same came to Jesus by night</p><p>Observe, I. Who this was.</p>',
    [1 => 'there was a man of the', 2 => 'the same came to jesus by'], []) === '<p>Observe, I. Who this was.</p>');
check('commentary: an entry is never left empty',
    drop_reprinted_passage("<p>$verse</p>", $opens, [16 => $verse]) === "<p>$verse</p>");
check('commentary: the catalog has every work Larry listed', count(commentary_catalog()) >= 31);
check('commentary: works of doubtful standing are held back', commentary_unchecked() === ['newell']);

// ============ Bible marks ============
require_once APP_DIR . '/lib/bible_marks.php';
$clean = fn(array $in) => bible_mark_clean($in);
check('marks: a highlight is tidied', $clean(['kind' => 'highlight', 'book' => 'jhn', 'chapter' => 3, 'verse' => 16, 'color' => 'green'])
    === ['kind' => 'highlight', 'book' => 'JHN', 'chapter' => 3, 'verse' => 16, 'end_verse' => 16, 'color' => 'green', 'body' => '', 'version' => '']);
check('marks: an unknown color becomes the first one', $clean(['kind' => 'highlight', 'book' => 'JHN', 'chapter' => 3, 'verse' => 16, 'color' => 'puce'])['color'] === 'yellow');
check('marks: a note needs something written in it', $clean(['kind' => 'note', 'book' => 'JHN', 'chapter' => 3, 'verse' => 16, 'body' => '  ']) === null);
check('marks: a bookmark keeps no color', $clean(['kind' => 'bookmark', 'book' => 'JHN', 'chapter' => 3, 'verse' => 16, 'color' => 'green'])['color'] === '');
check('marks: a chapter the book hasn\'t got is refused', $clean(['kind' => 'bookmark', 'book' => 'JUD', 'chapter' => 2, 'verse' => 1]) === null);
check('marks: an unknown book is refused', $clean(['kind' => 'bookmark', 'book' => 'XYZ', 'chapter' => 1, 'verse' => 1]) === null);
check('marks: a backwards range is put right', $clean(['kind' => 'highlight', 'book' => 'PSA', 'chapter' => 23, 'verse' => 4, 'end_verse' => 2])['end_verse'] === 4);
check('marks: a made-up kind is refused', $clean(['kind' => 'scribble', 'book' => 'PSA', 'chapter' => 23, 'verse' => 1]) === null);

// ============ Bible references ============
require_once APP_DIR . '/lib/bible_refs.php';
$refs = fn($t) => array_map(fn($r) => $r['ref'], bible_find_refs($t));
check('refs: book chapter and verse', $refs('see John 3:16 today') === ['John 3:16']);
check('refs: abbreviations and ranges', $refs('1 Jn 2:1-3 and Rom 8:28') === ['1 John 2:1-3', 'Romans 8:28']);
check('refs: a whole chapter', $refs('read Psalm 23') === ['Psalms 23']);
check('refs: the schedule\'s own wording', $refs('1 Chronicles 17:1-27 | 2 Samuel 7:18-29') === ['1 Chronicles 17:1-27', '2 Samuel 7:18-29']);
check('refs: one-chapter books count verses', $refs('Jude 5') === ['Jude 1:5']);
check('refs: not a reference without a book', $refs('meet at 3:16, room 2 Peter Street') === []);
check('refs: a chapter beyond the book is refused', $refs('John 99') === []);
check('refs: longest name wins', $refs('Song of Solomon 2:1') === ['Song of Solomon 2:1']);

// ============ Formatting ============
$cases = [
    ['**bold** __it__ ~~x~~ ||s||', 'bold it x s', ['bold=bold', 'italic=it', 'strike=x', 'spoiler=s']],
    ['🙏 **pray** 🙏', '🙏 pray 🙏', ['bold=pray']],                         // emoji before: UTF-16 offsets
    ['`code **not bold**`', 'code **not bold**', ['code=code **not bold**']],
    ["```js\nlet x = 1;\n```", 'let x = 1;', ['pre=let x = 1;']],
    ["> one\n> two\nthree", "one\ntwo\nthree", ["blockquote=one\ntwo"]],
    ['[site](example.com)', 'site', ['text_link=site->https://example.com']],
    ['[bad](javascript:alert(1))', '[bad](javascript:alert(1))', []],     // unsafe link left as text
    ['see https://a.org/x.', 'see https://a.org/x.', ['url=https://a.org/x']],
    ['2 ** 3 ** 4', '2 ** 3 ** 4', []],                                   // no spaces inside markers
    ['  padded  ', 'padded', []],
    ['me@example.com #Romans', 'me@example.com #Romans', ['email=me@example.com', 'hashtag=#Romans']],
];
foreach ($cases as [$in, $text, $expect]) {
    [$t, $e] = compose_message($in);
    check("format: $in", $t === $text && ents($t, $e) === $expect, json_encode([$t, ents($t, $e)], JSON_UNESCAPED_UNICODE));
}

// ============ Setup: people and topics ============
$mk = function (string $name, string $role, ?string $username) {
    q("INSERT INTO users (display_name, username, password_hash, role, status) VALUES (?, ?, 'x', ?, 'active')", [$name, $username, $role]);
    return q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch();
};
$owner = $mk('Olive Owner', 'owner', 'olive');
$ann = $mk('Ann Member', 'member', 'ann');
$bob = $mk('Bob Member', 'member', null);
q("INSERT INTO topics (title, is_general) VALUES ('General', 1), ('Open', 0)");
q("INSERT INTO topics (title, is_closed, created_by) VALUES ('Closed', 1, ?)", [$owner['id']]);
[$general, $open, $closed] = [1, 2, 3];

// ============ Sending ============
$m1 = send_message($ann, $open, 'Hello **all**, see @olive', [], 0, [], null);
$row = q('SELECT * FROM messages WHERE id = ?', [$m1])->fetch();
check('send: stored text', $row['text'] === 'Hello all, see @olive');
check('send: @username links to member', str_contains($row['entities'], '"user_id":' . $owner['id']));
check('send: mention recorded', (bool)q('SELECT 1 FROM mentions WHERE message_id = ? AND user_id = ?', [$m1, $owner['id']])->fetch());
check('send: topic last message', (int)q('SELECT last_message_id FROM topics WHERE id = ?', [$open])->fetchColumn() === $m1);
check('send: change logged', (bool)q("SELECT 1 FROM changes WHERE message_id = ? AND kind = 'new'", [$m1])->fetch());
check('send: sender has read it', (int)q('SELECT last_read_id FROM read_state WHERE user_id = ? AND topic_id = ?', [$ann['id'], $open])->fetchColumn() === $m1);
check('send: empty refused', throws(fn() => send_message($ann, $open, '   ', [], 0, [], null), 'empty'));
check('send: too long refused', throws(fn() => send_message($ann, $open, str_repeat('a', 4097), [], 0, [], null), '4096'));

$m2 = send_message($bob, $open, 'Reply to Ann', [], $m1, [], null);
check('reply: stored', (int)q('SELECT reply_to_id FROM messages WHERE id = ?', [$m2])->fetchColumn() === $m1);
check('reply: counts as mention of Ann', (bool)q('SELECT 1 FROM mentions WHERE message_id = ? AND user_id = ?', [$m2, $ann['id']])->fetch());
$m3 = send_message($bob, $general, 'Cross-topic reply', [], $m1, [], null);
check('reply: to another topic is dropped', q('SELECT reply_to_id FROM messages WHERE id = ?', [$m3])->fetchColumn() === null);
$m4 = send_message($ann, $open, 'Hi @Bob Member', [['user_id' => $bob['id'], 'name' => 'Bob Member']], 0, [], null);
check('mention by name (no username)', str_contains(q('SELECT entities FROM messages WHERE id = ?', [$m4])->fetchColumn(), '"mention_name"'));

// ============ Closed topics ============
check('closed: member refused', throws(fn() => send_message($ann, $closed, 'hi', [], 0, [], null), 'closed'));
check('closed: owner/creator allowed', send_message($owner, $closed, 'announcement', [], 0, [], null) > 0);

// ============ Editing ============
edit_message($ann, $m1, 'Hello __everyone__', []);
$row = q('SELECT * FROM messages WHERE id = ?', [$m1])->fetch();
check('edit: text changed', $row['text'] === 'Hello everyone' && $row['edited_at'] !== null);
check('edit: old mention removed', !q('SELECT 1 FROM mentions WHERE message_id = ? AND user_id = ?', [$m1, $owner['id']])->fetch());
check('edit: someone else refused', throws(fn() => edit_message($bob, $m1, 'hacked', []), 'own'));
check('edit: even admin cannot edit others', throws(fn() => edit_message($owner, $m1, 'hacked', []), 'own'));
q('UPDATE messages SET created_at = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = ?', [$m4]);
check('edit: after 48 hours refused', throws(fn() => edit_message($ann, $m4, 'late', []), '48 hours'));
$old = send_message($owner, $open, 'admin post', [], 0, [], null);
q('UPDATE messages SET created_at = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = ?', [$old]);
check('edit: admin has no time limit', !throws(fn() => edit_message($owner, $old, 'admin post, edited', [])));

// ============ Reactions ============
react($bob, $m1, '👍');
react($bob, $m1, '❤');
check('react: one per person (replaced)', q('SELECT GROUP_CONCAT(emoji) FROM reactions WHERE message_id = ?', [$m1])->fetchColumn() === '❤');
react($bob, $m1, '❤');
check('react: same emoji again removes it', !q('SELECT 1 FROM reactions WHERE message_id = ?', [$m1])->fetch());
check('react: unlisted emoji refused', throws(fn() => react($bob, $m1, '🤡'), 'available'));

// ============ Polls ============
$p = send_message($ann, $open, '', [], 0, [], ['question' => 'Tea?', 'options' => ['Yes', 'No'], 'anonymous' => false, 'multiple' => false]);
$opts = q('SELECT id FROM poll_options WHERE message_id = ? ORDER BY position', [$p])->fetchAll(PDO::FETCH_COLUMN);
vote($bob, $p, [$opts[0]]);
vote($bob, $p, [$opts[1]]);
check('poll: re-vote replaces', q('SELECT GROUP_CONCAT(option_id) FROM poll_votes WHERE message_id = ?', [$p])->fetchColumn() == $opts[1]);
check('poll: single-answer refuses two', throws(fn() => vote($bob, $p, $opts), 'one answer'));
vote($bob, $p, []);
check('poll: retract', !q('SELECT 1 FROM poll_votes WHERE message_id = ?', [$p])->fetch());
check('poll: needs 2 options', throws(fn() => send_message($ann, $open, '', [], 0, [], ['question' => 'Q', 'options' => ['only']]), 'between 2 and 10'));
check('poll: only author/admin can stop', throws(fn() => close_poll($bob, $p), 'author'));
close_poll($owner, $p);
check('poll: closed poll refuses votes', throws(fn() => vote($bob, $p, [$opts[0]]), 'closed'));

// ============ Pins ============
check('pin: members refused', throws(fn() => set_pin($ann, $m2, true), 'admins'));
set_pin($owner, $m2, true);
check('pin: pinned', (bool)q('SELECT 1 FROM pins WHERE message_id = ?', [$m2])->fetch());
check('pin: posts a service message', (bool)q("SELECT 1 FROM messages WHERE kind = 'service' AND service LIKE '%pinned%'")->fetch());

// ============ Deleting ============
check('delete: others refused', throws(fn() => delete_message($bob, $m1), 'own'));
delete_message($owner, $m2);
check('delete: admin can delete anyone', q('SELECT deleted_at FROM messages WHERE id = ?', [$m2])->fetchColumn() !== null);
check('delete: unpins too', !q('SELECT 1 FROM pins WHERE message_id = ?', [$m2])->fetch());
delete_message($ann, $m1);
check('delete: own message', q('SELECT deleted_at FROM messages WHERE id = ?', [$m1])->fetchColumn() !== null);
check('delete: reacting to deleted refused', throws(fn() => react($bob, $m1, '👍'), 'deleted'));

// ============ Sync ============
$cursor = change_cursor();
$m5 = send_message($ann, $open, 'sync me', [], 0, [], null);
$s = sync_changes((int)$bob['id'], $open, $cursor);
check('sync: new message arrives', in_array($m5, array_column($s['messages'], 'id'), true));
check('sync: unread counted for others', array_column($s['topics'], 'unread', 'id')[$open] >= 1);
$cursor = change_cursor();
delete_message($ann, $m5);
$s = sync_changes((int)$bob['id'], $open, $cursor);
check('sync: deletion arrives', in_array($m5, $s['deleted'], true));
check('sync: nothing new is cheap', array_keys(sync_changes((int)$bob['id'], $open, change_cursor())) === ['cursor']);

// ============ Rate limit ============
q('UPDATE messages SET created_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR');
for ($i = 0; $i < MESSAGES_PER_MINUTE; $i++) {
    send_message($bob, $general, "msg $i", [], 0, [], null);
}
check('rate limit: 21st message in a minute refused', throws(fn() => send_message($bob, $general, 'one too many', [], 0, [], null), 'quickly'));


// ============ Daily reading ============
$csv = tempnam(sys_get_temp_dir(), 'sched');
file_put_contents($csv, "\xEF\xBB\xBFdate,readings_merged,readings\n19-Sep-26,1 Chronicles 17,x\n2-Oct-26,Missing zero works,x\n25-Mar-28,Revelation 21,x\n25-Mar-28,Revelation 22,x\n31-Feb-26,Impossible date,x\n20-Sep-26,,x\n");
[$rows, $problems] = parse_schedule_csv($csv);
unlink($csv);
check('schedule: dates parsed, leading zero optional', array_column($rows, 0) === ['2026-09-19', '2026-10-02', '2028-03-25', '2028-03-25']);
check('schedule: bad date and empty reading reported', count($problems) === 2, json_encode($problems));
replace_schedule($rows);
check('schedule: two readings on one day both kept', readings_for('2028-03-25') === ['Revelation 21', 'Revelation 22']);
check('reading text: bot format', reading_text('2026-09-19', ['1 Chronicles 17']) === "👉 Sat., Sep 19, 2026: \n1 Chronicles 17");

q("INSERT INTO users (display_name, role, status) VALUES ('Daily Reading', 'system', 'active')");
$reader = (int)db()->lastInsertId();
q("INSERT INTO topics (title) VALUES ('Scheduled Reading')");
$reading_topic = (int)db()->lastInsertId();
set_setting('daily_reading_user', (string)$reader);
set_setting('daily_reading_topic', (string)$reading_topic);
check('reading: failure leaves the day retryable', (function () {
    set_setting('daily_reading_topic', '0');
    try { post_daily_reading('2026-09-19', true); } catch (ActionError $e) { /* expected */ }
    return !q("SELECT 1 FROM reading_posts WHERE reading_date = '2026-09-19'")->fetch();
})());
set_setting('daily_reading_topic', (string)$reading_topic);
set_setting('daily_reading_time_utc', '05:00');
set_setting('daily_reading_enabled', '1');
q('DELETE FROM messages WHERE user_id = ?', [$reader]);
check('reading: not before the posting time', str_starts_with(post_daily_reading(gmdate('Y-m-d', strtotime('+2 day'))), 'not yet'));
check('reading: nothing scheduled is reported', str_starts_with(post_daily_reading('2020-01-01'), 'no reading'));
$status = post_daily_reading('2028-03-25', true);
check('reading: posts', str_starts_with($status, 'posted'), $status);
$posted = q('SELECT kind, text FROM messages WHERE user_id = ? ORDER BY id', [$reader])->fetchAll();
check('reading: reading then poll', array_column($posted, 'kind') === ['text', 'poll'] && str_contains($posted[0]['text'], "Revelation 21\nRevelation 22"));
$poll = q("SELECT p.question, p.is_anonymous, GROUP_CONCAT(o.text ORDER BY o.position SEPARATOR ' ') opts FROM polls p JOIN poll_options o ON o.message_id = p.message_id JOIN messages m ON m.id = p.message_id WHERE m.user_id = ? GROUP BY p.message_id", [$reader])->fetch();
check('reading: poll is "Finished?" with ☑️ 📖 ✅, public', $poll['question'] === 'Finished?' && $poll['opts'] === '☑️ 📖 ✅' && !$poll['is_anonymous']);
check('reading: never twice', str_starts_with(post_daily_reading('2028-03-25', true), 'already'));
set_setting('daily_reading_enabled', '0');
check('reading: off means off', post_daily_reading('2026-09-19', true) === 'off');


// ============ Invites ============
$code = new_invite($owner, null, 'For Carol', 2, 14);
check('invite: usable', usable_invite($code) !== null);
$carol = redeem_invite(usable_invite($code), 'Carol New', 'carol', 'longenough');
check('invite: creates an active member', $carol['status'] === 'active' && $carol['role'] === 'member' && $carol['username'] === 'carol');
check('invite: starts with history read', (bool)q('SELECT 1 FROM read_state WHERE user_id = ?', [$carol['id']])->fetch());
check('invite: username must be unique', throws(fn() => redeem_invite(usable_invite($code), 'Other', 'carol', 'longenough'), 'taken'));
check('invite: short password refused', throws(fn() => redeem_invite(usable_invite($code), 'Other', 'dave', 'short'), '8 characters'));
redeem_invite(usable_invite($code), 'Dave New', 'dave', 'longenough');
check('invite: used up after its limit', usable_invite($code) === null);
$ann_row = q('SELECT * FROM users WHERE id = ?', [$ann['id']])->fetch();
$mcode = new_invite($ann_row, null, '', 100, 365);
$minv = q('SELECT max_uses, expires_at FROM invite_codes WHERE code = ?', [$mcode])->fetch();
check('invite: member links admit one person', (int)$minv['max_uses'] === 1);
check('invite: member link counts against the 5', invites_left($ann_row) === MEMBER_INVITES_PER_WEEK - 1);
q('UPDATE invite_codes SET revoked_at = UTC_TIMESTAMP() WHERE code = ?', [$mcode]);
check('invite: unused revoked link gives its slot back', invites_left($ann_row) === MEMBER_INVITES_PER_WEEK);
for ($i = 0; $i < MEMBER_INVITES_PER_WEEK; $i++) { new_invite($ann_row, null, '', 1, 14); }
check('invite: member stops at 5', throws(fn() => new_invite($ann_row, null, '', 1, 14), 'used your'));
q('DELETE FROM invite_codes WHERE created_by = ?', [$ann['id']]);
$inv = q('SELECT * FROM invite_codes WHERE code = ?', [$code])->fetch();
check('invite: cannot be over-used even if checked earlier', throws(fn() => redeem_invite($inv, 'Eve', 'eve', 'longenough'), 'used up'));
$exp = new_invite($owner, null, '', 1, 1);
q('UPDATE invite_codes SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE code = ?', [$exp]);
check('invite: expired link refused', usable_invite($exp) === null);
$rev = new_invite($owner, null, '', 1, 14);
q('UPDATE invite_codes SET revoked_at = UTC_TIMESTAMP() WHERE code = ?', [$rev]);
check('invite: canceled link refused', usable_invite($rev) === null);
check('invite: junk code refused', usable_invite('../../etc/passwd') === null);
q("INSERT INTO users (display_name, telegram_id, status) VALUES ('Imported Irene', 987654, 'unclaimed')");
$irene = (int)db()->lastInsertId();
$claim = new_invite($owner, $irene, 'claim', 1, 14);
$got = redeem_invite(usable_invite($claim), 'Irene', 'irene', 'longenough');
check('claim link: takes over the imported account', (int)$got['id'] === $irene && $got['status'] === 'active' && $got['telegram_id'] == 987654);

// ============ Topic management ============
check('topics: members cannot create', throws(fn() => create_topic($ann, 'Mine', null, 0), 'Only admins'));
$tt = create_topic($owner, '  New   Book  ', 'harp', 3);
check('topics: admin creates (name tidied)', q('SELECT title FROM topics WHERE id = ?', [$tt])->fetchColumn() === 'New Book');
check('topics: creation posts a service message', (bool)q("SELECT 1 FROM messages WHERE topic_id = ? AND kind = 'service' AND service LIKE '%topic_created%'", [$tt])->fetch());
check('topics: bad icon name refused', throws(fn() => edit_topic($owner, $tt, 'X', '../evil', 0), 'icon'));
edit_topic($owner, $tt, 'Renamed Book', 'lion', 2);
check('topics: rename posts a service message', (bool)q("SELECT 1 FROM messages WHERE topic_id = ? AND service LIKE '%topic_renamed%'", [$tt])->fetch());
check('topics: member cannot edit others\' topic', throws(fn() => edit_topic($ann, $tt, 'Hack', null, 0), 'creator or an admin'));
close_topic($owner, $tt, true);
check('topics: closed topic refuses members', throws(fn() => send_message($ann, $tt, 'hi', [], 0, [], null), 'closed'));
close_topic($owner, $tt, false);
check('topics: General cannot be closed or deleted', throws(fn() => close_topic($owner, $general, true), 'General') && throws(fn() => delete_topic($owner, $general), 'General'));
$ids = [];
for ($i = 0; $i < 5; $i++) { $ids[] = create_topic($owner, "Pin $i", null, 0); pin_topic($owner, end($ids), true); }
check('topics: sixth pin refused', throws(fn() => pin_topic($owner, $tt, true), 'more than 5'));
pin_topic($owner, $ids[0], false);
check('topics: unpin renumbers', q('SELECT GROUP_CONCAT(pin_order ORDER BY pin_order) FROM topics WHERE pin_order IS NOT NULL')->fetchColumn() === '1,2,3,4');
check('topics: members cannot pin', throws(fn() => pin_topic($ann, $tt, true), 'Only admins'));
send_message($ann, $tt, 'a message', [], 0, [], null);
delete_topic($owner, $tt);
check('topics: delete removes topic and its messages', !q('SELECT 1 FROM topics WHERE id = ?', [$tt])->fetch() && !q('SELECT 1 FROM messages WHERE topic_id = ?', [$tt])->fetch());

// ============ Member invites ============
check('member invites: 5 a week', invites_left($ann) === 5);
$ann_code = new_invite($ann, null, 'friend', 50, 0);
$ai = q('SELECT max_uses, expires_at FROM invite_codes WHERE code = ?', [$ann_code])->fetch();
check('member invites: forced to one use, 14 days', (int)$ai['max_uses'] === 1 && $ai['expires_at'] !== null);
for ($i = 0; $i < 4; $i++) { new_invite($ann, null, '', 1, 14); }
check('member invites: sixth refused', throws(fn() => new_invite($ann, null, '', 1, 14), 'this week'));
check('member invites: cannot make claim links', throws(fn() => new_invite($bob, (int)$ann['id'], '', 1, 14)));
check('admins: unlimited', invites_left($owner) === null);
$joined = redeem_invite(usable_invite($ann_code), 'Friend of Ann', 'friendann', 'longenough');
check('member invite: redeems (owner alert never blocks)', $joined['username'] === 'friendann');

// ============ Push ============
require_once APP_DIR . '/lib/push.php';
check('push: library and its HTTP client load', (function () { try { web_push(); return true; } catch (Throwable $e) { return false; } })());
check('push: no devices means nothing sent', push_test((int)$ann['id']) === [0, 0]);
check('push: incomplete subscription refused', throws(fn() => save_subscription((int)$ann['id'], ['endpoint' => 'http://x'], 'x'), 'incomplete'));

// ============ Imported polls ============
$ip = send_message($owner, $open, '', [], 0, [], ['question' => 'Old?', 'options' => ['A', 'B'], 'anonymous' => false, 'multiple' => false]);
q('UPDATE polls SET imported = 1 WHERE message_id = ?', [$ip]);
q('UPDATE poll_options SET imported_votes = 5 WHERE message_id = ? AND position = 0', [$ip]);
$optA = (int)q('SELECT id FROM poll_options WHERE message_id = ? AND position = 0', [$ip])->fetchColumn();
vote($ann, $ip, [$optA]);
$u = [];
$iph = hydrate_messages(q('SELECT * FROM messages WHERE id = ?', [$ip])->fetchAll(), (int)$ann['id'], $u)[0]['poll'];
check('imported poll: open for votes, counted on top of Telegram\'s', $iph['total'] === 6 && $iph['voted']);
vote($ann, $ip, []);
check('imported poll: vote can be taken back', q('SELECT COUNT(*) FROM poll_votes WHERE message_id = ?', [$ip])->fetchColumn() == 0);

// ============ Link previews ============
require_once APP_DIR . '/lib/previews.php';
check('preview: only http(s) links', preview_normalize('file:///etc/passwd') === null && preview_normalize('javascript:alert(1)') === null);
check('preview: fragment dropped', preview_normalize(' https://example.com/a#top ') === 'https://example.com/a');
foreach (['127.0.0.1', '10.1.2.3', '192.168.0.5', '172.16.9.9', '169.254.169.254', '100.64.1.1', '0.0.0.0', 'localhost'] as $bad) {
    check("preview: refuses private address $bad", preview_public_ip($bad) === null);
}
check('preview: refuses odd ports', preview_get('http://example.com:8080/', 1000, '*/*') === null);
check('preview: refuses redirects into the private network', preview_get('http://127.0.0.1/', 1000, '*/*') === null);
check('preview: relative image paths resolved', preview_absolute('/img/a.png', 'https://site.org/x/y') === 'https://site.org/img/a.png'
    && preview_absolute('b.png', 'https://site.org/x/y') === 'https://site.org/x/b.png' && preview_absolute('//cdn.org/c.png', 'https://site.org/') === 'https://cdn.org/c.png');
$ex = preview_for($owner, 'https://example.com/');
check('preview: a real page gives its title', ($ex['title'] ?? '') === 'Example Domain', json_encode($ex));
check('preview: cached after the first fetch', (int)q('SELECT COUNT(*) FROM link_previews WHERE url = ?', ['https://example.com/'])->fetchColumn() === 1);

// ============ Quote-reply ============
$qsrc = send_message($owner, $open, "First line.\nThe part I'm answering.", [], 0, [], null);
$qr = send_message($owner, $open, 'Agreed', [], $qsrc, [], null, "The part I'm answering.");
$u = [];
$qh = hydrate_messages(q('SELECT * FROM messages WHERE id = ?', [$qr])->fetchAll(), (int)$owner['id'], $u)[0];
check('quote-reply: the quoted words are kept and shown', ($qh['reply']['quote'] ?? '') === "The part I'm answering.");
$qbad = send_message($owner, $open, 'Hmm', [], $qsrc, [], null, 'words that are not there');
check('quote-reply: a quote not in the original becomes a plain reply', q('SELECT quote_text FROM messages WHERE id = ?', [$qbad])->fetchColumn() === null
    && (int)q('SELECT reply_to_id FROM messages WHERE id = ?', [$qbad])->fetchColumn() === $qsrc);

// ============ Unread reactions ============
$rxm = send_message($owner, $general, 'React to me', [], 0, [], null);
react($owner, $rxm, '👍');
$rxTopic = fn($who) => array_values(array_filter(topic_list((int)$who['id']), fn($t) => $t['id'] === $general))[0]['reactions'];
check('reactions: your own reaction is not "new"', $rxTopic($owner) === 0);
react($ann, $rxm, '❤');
check('reactions: someone else\'s reaction is new to the author', $rxTopic($owner) === 1 && unseen_reactions((int)$owner['id'], $general) === [$rxm]);
check('reactions: not new to anyone else', $rxTopic($ann) === 0);
mark_reactions_seen((int)$owner['id'], [$rxm]);
check('reactions: seeing the message clears it', $rxTopic($owner) === 0 && unseen_reactions((int)$owner['id'], $general) === []);

// ============ Pinned list: unpin all ============
$pa = send_message($owner, $general, 'pin me A', [], 0, [], null);
$pb = send_message($owner, $general, 'pin me B', [], 0, [], null);
set_pin($owner, $pa, true);
set_pin($owner, $pb, true);
q('UPDATE messages SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$pb]);
set_pin($owner, $pb, false);
check('pins: a deleted pinned message can still be unpinned', !q('SELECT 1 FROM pins WHERE message_id = ?', [$pb])->fetch());
check('pins: members cannot unpin', throws(fn() => set_pin($ann, $pa, false), 'admins'));

// ============ Direct messages (end-to-end encrypted) ============
require_once APP_DIR . '/lib/search.php';
$carol = q("SELECT * FROM users WHERE username = 'carol'")->fetch();   // an outsider to Ann and Bob's DM
$annR = q('SELECT * FROM users WHERE id = ?', [$ann['id']])->fetch();
$bobR = q('SELECT * FROM users WHERE id = ?', [$bob['id']])->fetch();
q('DELETE FROM messages WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 2 MINUTE', [$bob['id']]);   // Bob hit the rate limit earlier
$sealed = fn(string $x) => 'e2e1:' . base64_encode(random_bytes(28) . $x);   // stands in for what a browser seals
$lockedJson = fn() => json_encode(['v' => 1, 'salt' => 'c2FsdA==', 'rounds' => 600000, 'iv' => 'aXY=', 'ct' => base64_encode(random_bytes(40))]);
$convLock = fn() => json_encode(['v' => 1, 'epk' => base64_encode(random_bytes(60)), 'iv' => 'aXY=', 'ct' => base64_encode(random_bytes(40))]);
$dmt = dm_with($annR, (int)$bob['id']);
check('dm: one conversation per pair, either way round', dm_with($bobR, (int)$ann['id']) === $dmt);
check('dm: not with yourself', throws(fn() => dm_with($annR, (int)$ann['id']), 'yourself'));
check('dm: an admin can’t delete other people’s conversation', throws(fn() => delete_topic($owner, $dmt), 'no longer exists') && (bool)q('SELECT 1 FROM topics WHERE id = ?', [$dmt])->fetch());
check('dm: plain text is refused (it must be sealed in the browser)', throws(fn() => send_message($annR, $dmt, 'Secret plans', [], 0, [], null), 'securely'));
$s1 = $sealed('x');
$dm1 = send_message($annR, $dmt, $s1, [], 0, [], null);
check('dm: sealed text is stored exactly as sent, unreadable to the server', q('SELECT text FROM messages WHERE id = ?', [$dm1])->fetchColumn() === $s1);
$dmq = send_message($bobR, $dmt, $sealed('y'), [], $dm1, [], null, 'Secret plans');
check('dm: no plain quote is stored (it travels inside the sealed message)', q('SELECT quote_text FROM messages WHERE id = ?', [$dmq])->fetchColumn() === null);
check('dm: an edit must be sealed too', throws(fn() => edit_message($annR, $dm1, 'plain edit', []), 'securely'));
// Keys: the server only stores locked copies, and nobody can take over someone else's.
check('keys: bad key data refused', throws(fn() => save_keys($annR, 'not base64!', '{}', '{}', false), 'look right'));
save_keys($annR, base64_encode(random_bytes(91)), $lockedJson(), $lockedJson(), false);
check('keys: set up once; not replaced by accident', throws(fn() => save_keys($annR, base64_encode(random_bytes(91)), $lockedJson(), $lockedJson(), false), 'already'));
$st = dm_key_state((int)$ann['id'], q('SELECT * FROM topics WHERE id = ?', [$dmt])->fetch());
check('keys: a partner without keys is reported', $st['partner_key'] === null && !$st['has_key']);
save_dm_key($annR, $dmt, (int)$ann['id'], $convLock());
save_keys($bobR, base64_encode(random_bytes(91)), $lockedJson(), $lockedJson(), false);
$st = dm_key_state((int)$ann['id'], q('SELECT * FROM topics WHERE id = ?', [$dmt])->fetch());
check('keys: once the partner sets up, the conversation asks to be shared with them', $st['partner_needs'] === 'share' && $st['lock'] !== null);
save_dm_key($annR, $dmt, (int)$bob['id'], $convLock());
$bobLock = q('SELECT locked FROM dm_keys WHERE topic_id = ? AND user_id = ?', [$dmt, $bob['id']])->fetchColumn();
save_dm_key($annR, $dmt, (int)$bob['id'], $convLock());
check('keys: nobody can replace someone’s working conversation key', q('SELECT locked FROM dm_keys WHERE topic_id = ? AND user_id = ?', [$dmt, $bob['id']])->fetchColumn() === $bobLock);
check('keys: an outsider cannot set keys in a DM', throws(fn() => save_dm_key($carol, $dmt, (int)$carol['id'], $convLock()), 'isn’t available'));
// Bob loses his password and recovery code and starts fresh: Ann is asked to restore him.
save_keys($bobR, base64_encode(random_bytes(91)), $lockedJson(), $lockedJson(), true);
$st = dm_key_state((int)$ann['id'], q('SELECT * FROM topics WHERE id = ?', [$dmt])->fetch());
$stBob = dm_key_state((int)$bob['id'], q('SELECT * FROM topics WHERE id = ?', [$dmt])->fetch());
check('keys: after starting fresh, the other person is asked to restore access', $st['partner_needs'] === 'restore' && $stBob['lock'] === null && $stBob['has_key']);
save_dm_key($annR, $dmt, (int)$bob['id'], $convLock());
$stBob = dm_key_state((int)$bob['id'], q('SELECT * FROM topics WHERE id = ?', [$dmt])->fetch());
check('keys: restoring gives them a working key again', $stBob['lock'] !== null);
relock_keys($bobR, $lockedJson(), null);
check('keys: re-locking after a password change keeps the same key pair', (int)my_keys((int)$bob['id'])['version'] === 2);
// An outsider gets nowhere.
$dmTopic = q('SELECT * FROM topics WHERE id = ?', [$dmt])->fetch();
check('dm: outsider cannot see the conversation', !topic_visible((int)$carol['id'], $dmTopic) && topic_visible((int)$ann['id'], $dmTopic));
check('dm: outsider cannot post into it', throws(fn() => send_message($carol, $dmt, $sealed('z'), [], 0, [], null), 'isn’t available'));
check('dm: outsider cannot react, edit, delete or forward it', throws(fn() => react($carol, $dm1, '👍'), 'isn’t available')
    && throws(fn() => delete_message($carol, $dm1), 'isn’t available') && throws(fn() => forward_message($carol, $dm1, $general), 'isn’t available'));
check('dm: the server won\'t forward a DM message (only the app can read it)', throws(fn() => forward_message($annR, $dm1, $general), 'reload'));
$tmsg = send_message($owner, $general, 'From a topic', [], 0, [], null);
check('dm: forwarding into a DM needs sealed text', throws(fn() => forward_message($annR, $tmsg, $dmt), 'securely')
    && forward_message($annR, $tmsg, $dmt, $sealed('f')) > 0);
check('dm: not in anyone\'s topic list', !array_filter(topic_list((int)$carol['id']), fn($t) => $t['id'] === $dmt)
    && !array_filter(topic_list((int)$ann['id']), fn($t) => $t['id'] === $dmt));
check('dm: only in its members\' conversation lists, preview sealed', count(dm_list((int)$ann['id'])) === 1 && dm_list((int)$carol['id']) === []
    && dm_list((int)$bob['id'])[0]['last']['sealed'] !== null);
check('dm: never found by server search', search_messages((int)$ann['id'], 'secret', 0, 0)['results'] === []
    && !empty(search_messages((int)$ann['id'], 'secret', $dmt, 0)['on_device']));
check('dm: no polls', throws(fn() => send_message($annR, $dmt, '', [], 0, [], ['question' => 'Q?', 'options' => ['a', 'b']]), 'topics'));
check('dm: cannot be renamed, closed or deleted as a topic', !can_manage_topic($owner, $dmTopic));
// Blocking.
set_block($bobR, (int)$ann['id'], true);
check('dm: a blocked person cannot send', throws(fn() => send_message($annR, $dmt, $sealed('h'), [], 0, [], null), 'can’t message'));
check('dm: the blocker is told to unblock first', throws(fn() => send_message($bobR, $dmt, $sealed('i'), [], 0, [], null), 'Unblock'));
check('dm: a blocked person cannot react or pin', throws(fn() => react($annR, $dm1, '👍'), 'can’t message') && throws(fn() => set_pin($annR, $dm1, true), 'can’t message'));
set_block($bobR, (int)$ann['id'], false);
check('dm: unblocking lets messages through again', send_message($annR, $dmt, $sealed('j'), [], 0, [], null) > 0);
check('dm: either member may delete a message in it', (function () use ($bobR, $dm1) { delete_message($bobR, $dm1); return true; })());

// ============ Forwarding ============
$src = send_message($ann, $open, 'Hello from Ann', [], 0, [], null);
$f1 = forward_message($owner, $src, $general);
$fr = q('SELECT * FROM messages WHERE id = ?', [$f1])->fetch();
check('forward: copies the text into the other topic', (int)$fr['topic_id'] === $general && $fr['text'] === 'Hello from Ann');
check('forward: credits the original author', $fr['forwarded_from'] === 'Ann Member' && (int)$fr['forwarded_msg_id'] === $src);
$u = [];
$fh = hydrate_messages([$fr], (int)$owner['id'], $u)[0];
check('forward: links back to the original and its topic', $fh['fwd_id'] === $src && $fh['fwd_topic'] === $open);
$f2 = forward_message($owner, $f1, $open);
check('forward: a forward of a forward keeps the original', q('SELECT forwarded_msg_id FROM messages WHERE id = ?', [$f2])->fetchColumn() == $src);
$fp = send_message($ann, $open, '', [], 0, [], ['question' => 'Coffee?', 'options' => ['Yes', 'No'], 'anonymous' => false, 'multiple' => false]);
$fpf = forward_message($owner, $fp, $general);
$fpYes = (int)q('SELECT id FROM poll_options WHERE message_id = ? AND position = 0', [$fp])->fetchColumn();
vote($owner, $fpf, [$fpYes]);   // voting on the forwarded copy
$u = [];
$orig = hydrate_messages(q('SELECT * FROM messages WHERE id = ?', [$fp])->fetchAll(), (int)$ann['id'], $u)[0]['poll'];
$copy = hydrate_messages(q('SELECT * FROM messages WHERE id = ?', [$fpf])->fetchAll(), (int)$owner['id'], $u)[0]['poll'];
check('forward: a forwarded poll is the same poll (votes shared)', $orig['total'] === 1 && $copy['total'] === 1 && $copy['voted']);
check('poll: public polls list who voted', $orig['options'][0]['voters'] === [(int)$owner['id']]);
$anon = send_message($ann, $open, '', [], 0, [], ['question' => 'Secret?', 'options' => ['A', 'B'], 'anonymous' => true, 'multiple' => false]);
vote($owner, $anon, [(int)q('SELECT id FROM poll_options WHERE message_id = ? AND position = 0', [$anon])->fetchColumn()]);
$ah = hydrate_messages(q('SELECT * FROM messages WHERE id = ?', [$anon])->fetchAll(), (int)$ann['id'], $u)[0]['poll'];
check('poll: anonymous polls never list voters', $ah['total'] === 1 && $ah['options'][0]['voters'] === []);
check('forward: stopping a forwarded poll needs the original author or an admin', throws(fn() => close_poll($bob, $fpf), 'author'));
check('forward: into a closed topic refused', throws(fn() => forward_message($ann, $src, $closed), 'closed'));
q("INSERT INTO attachments (message_id, uploader_id, kind, path, name, mime, size) VALUES (?, ?, 'photo', 'x/y.jpg', '', 'image/jpeg', 10)", [$src, $ann['id']]);
$f3 = forward_message($owner, $src, $general);
check('forward: attachments shared with the copy', q('SELECT path FROM attachments WHERE message_id = ?', [$f3])->fetchColumn() === 'x/y.jpg');

// ============ Report ============
echo "$passed passed, " . count($failed) . " failed\n";
foreach ($failed as $f) {
    echo "  FAILED: $f\n";
}
exit($failed ? 1 : 0);
