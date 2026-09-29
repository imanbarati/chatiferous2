-- Cross-references between verses: about 345,000 of them, from openbible.info (CC BY), which
-- draws on the Treasury of Scripture Knowledge. Filled by cli/import_xrefs.php.
-- They are the same for every translation, so no version column.
CREATE TABLE bible_xrefs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book       CHAR(3) NOT NULL,                 -- the verse the reference is from
  chapter    SMALLINT UNSIGNED NOT NULL,
  verse      SMALLINT UNSIGNED NOT NULL,
  to_book    CHAR(3) NOT NULL,                 -- the passage it points to
  to_chapter SMALLINT UNSIGNED NOT NULL,
  to_verse   SMALLINT UNSIGNED NOT NULL,
  to_end     SMALLINT UNSIGNED NOT NULL,       -- same as to_verse unless it's a range
  votes      SMALLINT NOT NULL DEFAULT 0,      -- openbible.info's ranking: the strongest first
  KEY idx_from (book, chapter, verse, votes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
