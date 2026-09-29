-- End-to-end encrypted direct messages (see web/assets/e2e.js). The server keeps only public
-- keys and locked copies of keys, none of which it can open.
CREATE TABLE user_keys (
  user_id     INT UNSIGNED PRIMARY KEY,
  version     INT UNSIGNED NOT NULL DEFAULT 1,   -- goes up when someone has to start fresh
  public_key  TEXT NOT NULL,                     -- base64 SPKI (ECDH P-256)
  locked_pw   TEXT NOT NULL,                     -- private key, locked with their password
  locked_code TEXT NOT NULL,                     -- private key, locked with their recovery code
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each conversation's key, locked once for each of its two members.
CREATE TABLE dm_keys (
  topic_id    INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  key_version INT UNSIGNED NOT NULL,             -- the member's key pair it's locked to
  locked      TEXT NOT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (topic_id, user_id),
  CONSTRAINT fk_dmk_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
