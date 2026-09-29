-- Accounts. All times UTC.

CREATE TABLE users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  telegram_id    BIGINT UNSIGNED NULL UNIQUE,
  username       VARCHAR(32)  NULL UNIQUE,            -- chosen when the account is claimed
  display_name   VARCHAR(128) NOT NULL,
  password_hash  VARCHAR(255) NULL,
  role           ENUM('owner','admin','member','system') NOT NULL DEFAULT 'member',
  status         ENUM('unclaimed','active','deactivated','deleted') NOT NULL DEFAULT 'unclaimed',
  color_index    TINYINT UNSIGNED NOT NULL DEFAULT 0, -- Telegram's 7 peer colors: telegram_id % 7
  claimed_at     DATETIME NULL,
  last_login_at  DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-ins, claims and refusals, for admins to review.
CREATE TABLE auth_events (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  kind         VARCHAR(32)  NOT NULL,                 -- claim, join, login_telegram, login_password, login_failed, denied
  telegram_id  BIGINT UNSIGNED NULL,
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  detail       VARCHAR(255) NOT NULL DEFAULT '',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
