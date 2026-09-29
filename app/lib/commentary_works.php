<?php
// The catalog: which commentaries the app carries, in the order they're offered.
//
// Every one of these is out of copyright in the United States (published before 1930, and in most
// cases well before). Works whose status isn't plain are marked 'check' and are not imported
// until someone has settled it: Newell died in 1956, some ICC volumes are twentieth-century, and
// a patristic catena is only as free as the translation it quotes.
//
// scope: all | ot | nt | some — what the work covers, so the list can say when it has nothing.

const COMMENTARY_CATALOG = [
    // code             name                                  edition / full title
    ['mhcw', 'Matthew Henry (Full)', 'Commentary on the Whole Bible', 'Matthew Henry', '1708–10', 'all', 10],
    ['mhc', 'Matthew Henry (Concise)', 'Concise Commentary on the Bible', 'Matthew Henry', '1708–10', 'all', 11],
    ['barnes', 'Barnes', 'Notes on the Bible', 'Albert Barnes', '1834–85', 'all', 12],
    ['jfb', 'Jamieson-Fausset-Brown', 'Commentary Critical and Explanatory', 'Jamieson, Fausset and Brown', '1871', 'all', 13],
    ['gill', 'Gill', 'Exposition of the Whole Bible', 'John Gill', '1746–63', 'all', 14],
    ['clarke', 'Clarke', 'Commentary on the Bible', 'Adam Clarke', '1810–26', 'all', 15],
    ['poole', 'Poole', 'Annotations upon the Holy Bible', 'Matthew Poole', '1683–85', 'all', 16],
    ['calvin', 'Calvin', 'Commentaries', 'John Calvin', '1540–65', 'all', 17],
    ['wes', 'Wesley', 'Explanatory Notes', 'John Wesley', '1754–65', 'all', 18],
    ['pulpit', 'Pulpit Commentary', 'The Pulpit Commentary', 'Spence and Exell', '1880–97', 'all', 19],
    ['ellicott', 'Ellicott', "Commentary for English Readers", 'Charles John Ellicott', '1878–89', 'all', 20],
    ['cambridge', 'Cambridge Bible', 'Cambridge Bible for Schools and Colleges', 'J. J. S. Perowne (ed.)', '1882–1922', 'all', 21],
    ['expositors', "Expositor's Bible", "The Expositor's Bible", 'W. Robertson Nicoll (ed.)', '1887–96', 'all', 22],
    ['benson', 'Benson', 'Commentary on the Old and New Testaments', 'Joseph Benson', '1811–18', 'all', 23],
    ['lange', 'Lange', 'Commentary on the Holy Scriptures', 'John Peter Lange', '1857–84', 'all', 24],
    ['haydock', 'Haydock (Catholic)', "Haydock's Catholic Bible Commentary", 'George Leo Haydock', '1811', 'all', 25],
    ['maclaren', 'MacLaren', 'Expositions of Holy Scripture', 'Alexander MacLaren', '1904–10', 'all', 26],
    ['kelly', 'Kelly', 'Lectures and Notes', 'William Kelly', '1860–1900', 'some', 27],

    // Old Testament
    ['kad', 'Keil & Delitzsch', 'Biblical Commentary on the Old Testament', 'Keil and Delitzsch', '1861–75', 'ot', 30],
    ['tod', 'Treasury of David', 'The Treasury of David', 'C. H. Spurgeon', '1869–85', 'some', 31],

    // New Testament
    ['bengel', 'Bengel', 'Gnomon of the New Testament', 'Johann Albrecht Bengel', '1742', 'nt', 40],
    ['alford', 'Alford (Greek Testament)', 'The Greek Testament', 'Henry Alford', '1849–61', 'nt', 41],
    ['vws', 'Vincent (Word Studies)', 'Word Studies in the New Testament', 'Marvin R. Vincent', '1887', 'nt', 42],
    ['egt', "Expositor's Greek Testament", "The Expositor's Greek Testament", 'W. Robertson Nicoll (ed.)', '1897–1910', 'nt', 43],
    ['meyer', 'Meyer', 'Critical and Exegetical Commentary on the New Testament', 'H. A. W. Meyer', '1832–59', 'nt', 44],
    ['pnt', "People's New Testament", "The People's New Testament", 'B. W. Johnson', '1891', 'nt', 45],
    ['chrysostom', 'Chrysostom', 'Homilies', 'John Chrysostom', 'c. 390', 'nt', 46],
    ['hodge', 'Charles Hodge', 'Commentaries on Romans, 1 Corinthians and Ephesians', 'Charles Hodge', '1835–64', 'some', 47],

    // Settled: published well before 1930, so out of copyright in the United States.
    ['kretzmann', 'Kretzmann (Lutheran)', "Popular Commentary of the Bible", 'Paul E. Kretzmann', '1921–24', 'all', 51],
    ['icc', 'International Critical Commentary', 'The International Critical Commentary (old series)', 'various', '1895–1928', 'nt', 52],

    // The fathers on the gospels, in Newman's translation of 1842 — named, and long out of
    // copyright, unlike the modern compilations that go by the same title.
    ['catena', 'Catena Aurea (the Fathers)', 'Catena Aurea, tr. J. H. Newman, 1842', 'Thomas Aquinas', '1842', 'some', 50],

    // Left out by Larry's decision (2026-09-20). Newell's Romans (1938) and Revelation (1935)
    // appear never to have had their copyright renewed, which would put them in the public domain,
    // but that rests on a search finding nothing rather than on a date, and his Hebrews (1947) was
    // renewed. Not worth the doubt.
    ['newell', 'Newell', 'Verse-by-Verse Commentary', 'William R. Newell', '1935–38', 'some', 53, 'check'],
];

// The catalog as rows ready for the database.
function commentary_catalog(): array
{
    $out = [];
    foreach (COMMENTARY_CATALOG as $row) {
        [$code, $name, $edition, $author, $years, $scope, $sort] = $row;
        $out[] = [
            'code' => $code, 'name' => $name, 'edition' => $edition, 'author' => $author,
            'years' => $years, 'scope' => $scope, 'sort' => $sort,
            'license' => ($row[7] ?? '') === 'check' ? 'Status to be checked' : 'Public domain',
        ];
    }
    return $out;
}

// Works whose copyright hasn't been settled, which the importer leaves alone.
function commentary_unchecked(): array
{
    return array_values(array_map(fn($r) => $r[0], array_filter(COMMENTARY_CATALOG, fn($r) => ($r[7] ?? '') === 'check')));
}
