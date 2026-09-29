-- Chapters of the translations we read from API.Bible rather than hold (NKJV, NASB, NIV).
-- Not part of the corpus: this is a cache, one row per chapter someone has actually opened, and
-- the licence is what keeps it that way. See lib/api_bible.php.
CREATE TABLE bible_remote_chapters (
  version    VARCHAR(8) NOT NULL,
  book       CHAR(3)    NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  html       MEDIUMTEXT NOT NULL,
  verses     SMALLINT UNSIGNED NOT NULL,
  copyright  VARCHAR(255) NOT NULL DEFAULT '',   -- as the publisher words it; shown under the text
  fetched_at DATETIME   NOT NULL,
  PRIMARY KEY (version, book, chapter),
  KEY idx_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
