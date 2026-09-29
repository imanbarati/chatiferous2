-- Push notifications: each browser/phone a member turned notifications on for.
CREATE TABLE push_subscriptions (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  endpoint         VARCHAR(1000) NOT NULL,
  endpoint_hash    CHAR(64) NOT NULL UNIQUE,
  p256dh           VARCHAR(200) NOT NULL,
  auth             VARCHAR(100) NOT NULL,
  encoding         VARCHAR(20) NOT NULL DEFAULT 'aes128gcm',
  device           VARCHAR(120) NOT NULL DEFAULT '',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_success_at  DATETIME NULL,
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN notify_level ENUM('all','mentions','off') NOT NULL DEFAULT 'all',
  ADD COLUMN last_active_at DATETIME NULL,      -- updated by the live-update poll
  ADD COLUMN active_topic_id INT UNSIGNED NULL; -- the topic open at that moment

-- Start sending from messages posted after this point.
INSERT INTO settings (name, value) SELECT 'push_last_message_id', COALESCE(MAX(id), 0) FROM messages;
