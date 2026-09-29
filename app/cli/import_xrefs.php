<?php
// Imports the cross-references used by the reader's ✦ dialog.
//   php cli/import_xrefs.php [--file=cross_references.txt]
// Source: https://a.openbible.info/data/cross-references.zip (openbible.info, CC BY), about
// 345,000 references drawn from the Treasury of Scripture Knowledge. Safe to re-run.
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/bible_canon.php';

const XREF_URL = 'https://a.openbible.info/data/cross-references.zip';

// openbible.info's book abbreviations → our USFM codes.
const XREF_BOOKS = [
    'Gen' => 'GEN', 'Exod' => 'EXO', 'Lev' => 'LEV', 'Num' => 'NUM', 'Deut' => 'DEU', 'Josh' => 'JOS',
    'Judg' => 'JDG', 'Ruth' => 'RUT', '1Sam' => '1SA', '2Sam' => '2SA', '1Kgs' => '1KI', '2Kgs' => '2KI',
    '1Chr' => '1CH', '2Chr' => '2CH', 'Ezra' => 'EZR', 'Neh' => 'NEH', 'Esth' => 'EST', 'Job' => 'JOB',
    'Ps' => 'PSA', 'Prov' => 'PRO', 'Eccl' => 'ECC', 'Song' => 'SNG', 'Isa' => 'ISA', 'Jer' => 'JER',
    'Lam' => 'LAM', 'Ezek' => 'EZK', 'Dan' => 'DAN', 'Hos' => 'HOS', 'Joel' => 'JOL', 'Amos' => 'AMO',
    'Obad' => 'OBA', 'Jonah' => 'JON', 'Mic' => 'MIC', 'Nah' => 'NAM', 'Hab' => 'HAB', 'Zeph' => 'ZEP',
    'Hag' => 'HAG', 'Zech' => 'ZEC', 'Mal' => 'MAL',
    'Matt' => 'MAT', 'Mark' => 'MRK', 'Luke' => 'LUK', 'John' => 'JHN', 'Acts' => 'ACT', 'Rom' => 'ROM',
    '1Cor' => '1CO', '2Cor' => '2CO', 'Gal' => 'GAL', 'Eph' => 'EPH', 'Phil' => 'PHP', 'Col' => 'COL',
    '1Thess' => '1TH', '2Thess' => '2TH', '1Tim' => '1TI', '2Tim' => '2TI', 'Titus' => 'TIT',
    'Phlm' => 'PHM', 'Heb' => 'HEB', 'Jas' => 'JAS', '1Pet' => '1PE', '2Pet' => '2PE',
    '1John' => '1JN', '2John' => '2JN', '3John' => '3JN', 'Jude' => 'JUD', 'Rev' => 'REV',
];

$local = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--file=')) {
        $local = substr($a, 7);
    }
}
$path = $local;
if (!$path) {
    echo 'Downloading ' . XREF_URL . ' … ';
    $ch = curl_init(XREF_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180, CURLOPT_USERAGENT => 'Chatiferous Bible import']);
    $data = curl_exec($ch);
    curl_close($ch);
    if (!is_string($data) || strlen($data) < 100000) {
        exit("failed.\nDownload it yourself and pass --file=<cross_references.txt>.\n");
    }
    $zip_path = APP_DIR . '/data/cross-references.zip';
    file_put_contents($zip_path, $data);
    echo strlen($data), " bytes\n";
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        exit("Couldn't open the zip.\n");
    }
    $zip->extractTo(APP_DIR . '/data');
    $zip->close();
    @unlink($zip_path);
    $path = APP_DIR . '/data/cross_references.txt';
}
if (!is_file($path)) {
    exit("No cross_references.txt at $path.\n");
}

// "Gen.1.1  Prov.8.22-Prov.8.30  76"
$one = function (string $ref): ?array {
    $bits = explode('.', $ref);
    if (count($bits) !== 3 || !isset(XREF_BOOKS[$bits[0]])) {
        return null;
    }
    return [XREF_BOOKS[$bits[0]], (int)$bits[1], (int)$bits[2]];
};

$fh = fopen($path, 'r');
fgets($fh);                       // the header line
db()->beginTransaction();
q('DELETE FROM bible_xrefs');
$sql = 'INSERT INTO bible_xrefs (book, chapter, verse, to_book, to_chapter, to_verse, to_end, votes) VALUES ';
$rows = [];
$n = $skipped = 0;
$flush = function () use (&$rows, $sql) {
    if (!$rows) {
        return;
    }
    q($sql . implode(',', array_fill(0, count($rows) / 8, '(?,?,?,?,?,?,?,?)')), $rows);
    $rows = [];
};
while (($line = fgets($fh)) !== false) {
    $parts = explode("\t", trim($line));
    if (count($parts) < 3) {
        continue;
    }
    [$from, $to, $votes] = $parts;
    $f = $one($from);
    $range = explode('-', $to);
    $t = $one($range[0]);
    if (!$f || !$t) {
        $skipped++;
        continue;
    }
    $end = $t[2];
    if (isset($range[1])) {
        $e = $one($range[1]);
        if ($e && $e[0] === $t[0] && $e[1] === $t[1]) {
            $end = $e[2];
        }
    }
    array_push($rows, $f[0], $f[1], $f[2], $t[0], $t[1], $t[2], $end, (int)$votes);
    $n++;
    if (count($rows) >= 8 * 500) {
        $flush();
    }
}
$flush();
fclose($fh);
db()->commit();
if (!$local) {
    @unlink($path);
}
echo "$n cross-references imported" . ($skipped ? ", $skipped skipped (books we don't carry)" : '') . ".\n";
