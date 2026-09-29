-- The text of the translations we read from API.Bible, kept for one purpose: searching them
-- properly. Their own search cannot do the job — it has no boolean operators, its wildcard matches
-- a stemmed index rather than the words as written, and it answers "charity" with "chariot".
--
-- It is deliberately not bible_verses. That table is the corpus, which we own outright and may do
-- anything with; this one is licensed text, and keeping it apart makes the difference a property of
-- the schema rather than a remark in a comment. Nothing reads it but bible_search(): chapters are
-- fetched from the publisher as they are read, and the offline download refuses these versions.
CREATE TABLE bible_search_text (
  version  VARCHAR(8) NOT NULL,
  book     CHAR(3)    NOT NULL,
  chapter  SMALLINT UNSIGNED NOT NULL,
  verse    SMALLINT UNSIGNED NOT NULL,
  text     TEXT       NOT NULL,
  PRIMARY KEY (version, book, chapter, verse),
  FULLTEXT KEY ft_text (text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
