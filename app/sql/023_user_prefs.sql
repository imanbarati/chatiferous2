-- Settings a member chooses for themselves (the reader's font, size, background and so on), kept
-- with the account so they follow the person to another device, not only the one they set them on.
CREATE TABLE user_prefs (
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(40) NOT NULL,
  value      TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, name),
  CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
