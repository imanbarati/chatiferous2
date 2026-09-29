-- Single verses of the fetched translations, kept so a cross-reference preview can be worded as the
-- translation being read words it. One row per verse anyone has actually looked at through a
-- cross-reference — scattered singles, never a run. See lib/api_bible.php and LICENSED-TEXT.md.
CREATE TABLE bible_remote_verses (
  version    VARCHAR(8) NOT NULL,
  book       CHAR(3)    NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  verse      SMALLINT UNSIGNED NOT NULL,
  text       TEXT       NOT NULL,
  fetched_at DATETIME   NOT NULL,
  PRIMARY KEY (version, book, chapter, verse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What we have spent of API.Bible's monthly allowance. One row a month, so the figure can be read
-- without trusting anyone's memory of how much was fetched.
CREATE TABLE api_bible_usage (
  month  CHAR(7)     NOT NULL,          -- 2026-09
  calls  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
