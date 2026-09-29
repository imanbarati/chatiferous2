-- Invite links for people who aren't in the Telegram group, and personal claim
-- links (user_id set) for existing members who can't use Telegram login.
CREATE TABLE invite_codes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(32) NOT NULL UNIQUE,
  user_id     INT UNSIGNED NULL,                 -- set: claims that (imported) account
  note        VARCHAR(200) NOT NULL DEFAULT '',  -- who it's for
  max_uses    INT UNSIGNED NOT NULL DEFAULT 1,
  uses        INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  DATETIME NULL,
  revoked_at  DATETIME NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN invite_id INT UNSIGNED NULL;
