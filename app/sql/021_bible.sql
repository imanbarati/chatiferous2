-- The Bible reader's texts (optional feature: config 'bible_reader'). Filled by
-- cli/import_bible.php from public-domain USFM; nothing here is group-specific.

CREATE TABLE bible_books (
  version       VARCHAR(8)   NOT NULL,          -- WEB, BSB, KJV
  ord           TINYINT UNSIGNED NOT NULL,      -- 1..66, the usual order
  code          CHAR(3)      NOT NULL,          -- GEN, EXO, … (USFM book codes)
  name          VARCHAR(64)  NOT NULL,          -- "Genesis"
  abbrev        VARCHAR(16)  NOT NULL,          -- "Gen"
  chapters      TINYINT UNSIGNED NOT NULL,
  testament     ENUM('ot','nt') NOT NULL,
  biblehub      VARCHAR(32)  NOT NULL,          -- slug for biblehub.com links ("1_john")
  PRIMARY KEY (version, ord),
  KEY idx_code (version, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per chapter, rendered at import: reading a chapter costs one query and no parsing.
CREATE TABLE bible_chapters (
  version    VARCHAR(8) NOT NULL,
  book       CHAR(3)    NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  html       MEDIUMTEXT NOT NULL,
  verses     SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (version, book, chapter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The same text flat: for search, for quoting into the chat, and for counting verses.
CREATE TABLE bible_verses (
  version    VARCHAR(8) NOT NULL,
  book       CHAR(3)    NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  verse      SMALLINT UNSIGNED NOT NULL,
  text       TEXT NOT NULL,
  PRIMARY KEY (version, book, chapter, verse),
  FULLTEXT KEY ft_text (text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Footnotes ('f') and cross-references ('x'), kept apart from the text so a chapter can be drawn
-- without them.
CREATE TABLE bible_notes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version    VARCHAR(8) NOT NULL,
  book       CHAR(3)    NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  verse      SMALLINT UNSIGNED NOT NULL,
  kind       ENUM('f','x') NOT NULL,
  marker     VARCHAR(4) NOT NULL,               -- a, b, c… as shown in the text
  body       TEXT NOT NULL,
  refs       VARCHAR(255) NOT NULL DEFAULT '',  -- for cross-references: the references themselves
  KEY idx_place (version, book, chapter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where each member was reading. One row per book, so every book keeps its own place and the
-- picker returns you to where you stopped in that book; the newest row is where the reader opens.
-- 'place' is kept per version as well, since someone comparing versions shouldn't lose either.
CREATE TABLE bible_state (
  user_id    INT UNSIGNED NOT NULL,
  version    VARCHAR(8) NOT NULL,
  book       CHAR(3)    NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  verse      SMALLINT UNSIGNED NOT NULL DEFAULT 1,   -- the verse in view, so the eye lands right
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, version, book),
  KEY idx_recent (user_id, updated_at),
  CONSTRAINT fk_bstate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Today's reading keeps its own position, separate from ordinary reading.
CREATE TABLE bible_reading_state (
  user_id      INT UNSIGNED NOT NULL,
  reading_date DATE NOT NULL,
  book         CHAR(3) NOT NULL,
  chapter      SMALLINT UNSIGNED NOT NULL,
  verse        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  finished_at  DATETIME NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, reading_date),
  CONSTRAINT fk_brstate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
