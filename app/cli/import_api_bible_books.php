<?php
// The book list for each translation read from API.Bible. Book names and chapter counts are
// metadata, not scripture, so unlike the text itself they are ours to keep — and the reader needs
// them to draw the picker and work out what the next chapter is.
//
//   php cli/import_api_bible_books.php [--dry]
//
// The names come from the KJV rows we already hold, not from the API, because switching version
// shouldn't rename the books under you: API.Bible gives NKJV as "I John" and NIV as "Matt.", while
// its nameLong for the NKJV is "The First Epistle of JOHN". The API is asked only which books each
// translation actually carries.
require __DIR__ . '/../lib/bootstrap.php';
require_once APP_DIR . '/lib/bible.php';     // which pulls in api_bible.php

$dry = in_array('--dry', $argv, true);

if (!api_bible_map()) {
    fwrite(STDERR, "No api_bible_key/api_bible_ids in config — nothing to import.\n");
    exit(1);
}

// What the KJV rows know about each book, which is true of any translation of the same canon.
$base = [];
foreach (q('SELECT ord, code, name, abbrev, chapters, testament, biblehub FROM bible_books WHERE version = "KJV"')->fetchAll() as $b) {
    $base[$b['code']] = $b;
}
if (!$base) {
    fwrite(STDERR, "No KJV books to take the ordering from.\n");
    exit(1);
}

foreach (api_bible_map() as $version => $id) {
    $data = api_bible_get("/bibles/$id/books");
    $books = $data['data'] ?? null;
    if (!$books) {
        fwrite(STDERR, "$version: could not fetch the book list.\n");
        continue;
    }
    $kept = 0;
    $skipped = [];
    foreach ($books as $b) {
        $code = strtoupper((string)($b['id'] ?? ''));
        if (!isset($base[$code])) {
            $skipped[] = $code;        // deuterocanon and the like: we carry the 66 the rest have
            continue;
        }
        $row = $base[$code];
        $name = $row['name'];
        $abbrev = $row['abbrev'];
        if (!$dry) {
            q('INSERT INTO bible_books (version, ord, code, name, abbrev, chapters, testament, biblehub)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name), abbrev = VALUES(abbrev),
                 chapters = VALUES(chapters), testament = VALUES(testament), biblehub = VALUES(biblehub)',
                [$version, $row['ord'], $code, $name, $abbrev, $row['chapters'], $row['testament'], $row['biblehub']]);
        }
        $kept++;
    }
    printf("%s: %d books%s%s\n", $version, $kept, $dry ? ' (dry run)' : '',
        $skipped ? ' — skipped ' . implode(',', $skipped) : '');
}
