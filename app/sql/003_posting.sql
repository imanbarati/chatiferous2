-- Posting: a change log that clients poll, and uploads that wait for their message.

-- Every change to a message (new, edited, deleted, reacted to, voted on, pinned)
-- is logged here. Clients ask "what changed since change N?" every few seconds.
CREATE TABLE changes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  topic_id    INT UNSIGNED NOT NULL,
  message_id  INT UNSIGNED NULL,
  kind        VARCHAR(16) NOT NULL,     -- new, edit, delete, react, vote, pin
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_topic (topic_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files are uploaded first (message_id NULL), then attached when the message is sent.
ALTER TABLE attachments
  MODIFY message_id INT UNSIGNED NULL,
  ADD COLUMN uploader_id INT UNSIGNED NULL AFTER message_id,
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD KEY idx_pending (uploader_id, message_id);

-- One row per message sent, for the per-person posting rate limit.
ALTER TABLE messages ADD KEY idx_user_time (user_id, created_at);
