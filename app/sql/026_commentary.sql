-- The commentaries: a catalogue of works, and one row per work per chapter holding that
-- chapter's comments (as compressed JSON keyed by verse range). A chapter of one commentary is
-- 20-80 KB of text, so it is kept packed and unpacked when someone actually opens it; a whole
-- Bible of thirty works is then a few hundred megabytes rather than a couple of gigabytes.
--
-- These tables are left out of the nightly dump: every word of them can be imported again.
CREATE TABLE bible_works (
  code       VARCHAR(16) NOT NULL,
  name       VARCHAR(80) NOT NULL,
  edition    VARCHAR(160) NOT NULL DEFAULT '',
  author     VARCHAR(80) NOT NULL DEFAULT '',
  years      VARCHAR(32) NOT NULL DEFAULT '',
  scope      ENUM('all','ot','nt','some') NOT NULL DEFAULT 'all',
  source     VARCHAR(160) NOT NULL DEFAULT '',
  licence    VARCHAR(80)  NOT NULL DEFAULT 'Public domain',
  sort       SMALLINT NOT NULL DEFAULT 100,
  chapters   MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bible_commentary (
  work       VARCHAR(16) NOT NULL,
  book       CHAR(3)     NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  entries    MEDIUMBLOB  NOT NULL,        -- gzip of {"1": "<p>…</p>", "4-5": "…"}
  words      MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (work, book, chapter),
  KEY idx_place (book, chapter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
