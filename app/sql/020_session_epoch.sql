-- Changing a password (or resetting it with a claim link) ends the account's other sessions:
-- each session remembers the epoch it started in, and an old epoch no longer counts.
ALTER TABLE users ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0;

-- "Log in with Telegram" links work once.
CREATE TABLE telegram_logins_used (
  hash     CHAR(64) PRIMARY KEY,
  used_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_used (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
