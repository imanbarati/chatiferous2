-- What each member has been reading, most recent first, so the reader can offer a way back to
-- chapters they've just had open. One row per chapter per version; reopening moves it to the top.
CREATE TABLE bible_history (
  user_id    INT UNSIGNED NOT NULL,
  version    VARCHAR(8) NOT NULL,
  book       CHAR(3)    NOT NULL,
  chapter    SMALLINT UNSIGNED NOT NULL,
  seen_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, version, book, chapter),
  KEY idx_recent (user_id, seen_at),
  CONSTRAINT fk_bhist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
