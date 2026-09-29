-- Direct messages. A DM is a two-person "topic" (so replies, reactions, files, pins, live
-- updates and read markers all work as in topics), kept out of every topic list and visible
-- only to its two members. Its texts and files are encrypted at rest (lib/dm.php).
ALTER TABLE topics
  ADD COLUMN kind ENUM('topic', 'dm') NOT NULL DEFAULT 'topic' AFTER id,
  ADD COLUMN dm_a INT UNSIGNED NULL,          -- the two members, lower id first
  ADD COLUMN dm_b INT UNSIGNED NULL,
  ADD UNIQUE KEY uq_dm (dm_a, dm_b);

-- Files in a DM are stored encrypted.
ALTER TABLE attachments ADD COLUMN encrypted TINYINT(1) NOT NULL DEFAULT 0;

-- "Block": that person can no longer send you direct messages.
CREATE TABLE blocks (
  user_id    INT UNSIGNED NOT NULL,           -- who blocked
  blocked_id INT UNSIGNED NOT NULL,           -- whom
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
