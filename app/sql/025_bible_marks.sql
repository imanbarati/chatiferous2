-- What a member has marked in the Bible: highlights in a color, bookmarks, and private notes.
-- Marks belong to the reference, not to a version, so a verse marked while reading the KJV is
-- still marked when the same verse is read in the WEB; the version it was made in is kept as a
-- note of where the words came from.
CREATE TABLE bible_marks (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  kind       ENUM('highlight','bookmark','note') NOT NULL,
  book       CHAR(3)    NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  verse      SMALLINT UNSIGNED NOT NULL,
  end_verse  SMALLINT UNSIGNED NOT NULL,
  color     VARCHAR(8) NOT NULL DEFAULT '',
  body       TEXT       NOT NULL,
  version    VARCHAR(8) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_place (user_id, book, chapter),
  KEY idx_mine (user_id, kind, updated_at),
  CONSTRAINT fk_bmark_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
