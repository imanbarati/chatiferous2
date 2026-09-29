-- Topics, messages and everything attached to them. All times UTC.

CREATE TABLE topics (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(128) NOT NULL,
  icon_color      TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- index into Telegram's 6 topic colors
  icon_emoji      VARCHAR(16)  NULL,
  is_general      TINYINT(1)   NOT NULL DEFAULT 0,
  is_closed       TINYINT(1)   NOT NULL DEFAULT 0,
  pin_order       TINYINT UNSIGNED NULL,                 -- 1..5 when pinned to the top
  created_by      INT UNSIGNED NULL,
  last_message_id INT UNSIGNED NULL,
  telegram_id     BIGINT UNSIGNED NULL UNIQUE,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  topic_id         INT UNSIGNED NOT NULL,
  user_id          INT UNSIGNED NOT NULL,
  kind             ENUM('text','poll','service') NOT NULL DEFAULT 'text',
  text             MEDIUMTEXT NOT NULL,
  entities         MEDIUMTEXT NULL,          -- JSON: [{type, offset, length, url?, user_id?, language?}], UTF-16 offsets
  service          TEXT NULL,                -- JSON for kind=service: {action, title?, message_id?}
  reply_to_id      INT UNSIGNED NULL,
  forwarded_from   VARCHAR(128) NULL,
  extra_reactions  TEXT NULL,                -- JSON {emoji: count}: imported reactions whose reactors aren't known
  created_at       DATETIME NOT NULL,
  edited_at        DATETIME NULL,
  deleted_at       DATETIME NULL,
  telegram_id      BIGINT UNSIGNED NULL UNIQUE,
  KEY idx_topic (topic_id, id),
  KEY idx_user (user_id),
  CONSTRAINT fk_msg_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_user  FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reactions (
  message_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  emoji      VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (message_id, user_id),             -- one reaction per person, as in Telegram
  CONSTRAINT fk_react_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE polls (
  message_id     INT UNSIGNED PRIMARY KEY,
  question       VARCHAR(300) NOT NULL,
  is_anonymous   TINYINT(1) NOT NULL DEFAULT 0,
  multiple       TINYINT(1) NOT NULL DEFAULT 0,
  is_closed      TINYINT(1) NOT NULL DEFAULT 0,
  imported       TINYINT(1) NOT NULL DEFAULT 0,  -- results only; voters unknown
  CONSTRAINT fk_poll_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE poll_options (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id     INT UNSIGNED NOT NULL,
  position       TINYINT UNSIGNED NOT NULL,
  text           VARCHAR(100) NOT NULL,
  imported_votes INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_poll (message_id),
  CONSTRAINT fk_opt_poll FOREIGN KEY (message_id) REFERENCES polls(message_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE poll_votes (
  option_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  message_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (option_id, user_id),
  KEY idx_poll_user (message_id, user_id),
  CONSTRAINT fk_vote_opt FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pins (
  message_id INT UNSIGNED PRIMARY KEY,
  topic_id   INT UNSIGNED NOT NULL,
  pinned_by  INT UNSIGNED NULL,
  pinned_at  DATETIME NOT NULL,
  KEY idx_topic (topic_id, pinned_at),
  CONSTRAINT fk_pin_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attachments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id  INT UNSIGNED NOT NULL,
  kind        ENUM('photo','video','file','voice','animation') NOT NULL,
  path        VARCHAR(255) NULL,           -- relative to the private uploads folder; NULL if not available
  name        VARCHAR(255) NOT NULL DEFAULT '',
  mime        VARCHAR(100) NOT NULL DEFAULT '',
  size        INT UNSIGNED NOT NULL DEFAULT 0,
  width       SMALLINT UNSIGNED NULL,
  height      SMALLINT UNSIGNED NULL,
  duration    INT UNSIGNED NULL,
  KEY idx_msg (message_id),
  CONSTRAINT fk_att_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE read_state (
  user_id      INT UNSIGNED NOT NULL,
  topic_id     INT UNSIGNED NOT NULL,
  last_read_id INT UNSIGNED NOT NULL DEFAULT 0,
  muted        TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, topic_id),
  CONSTRAINT fk_rs_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Members (other than the author) that a message @mentions or replies to; drives the @ badge.
CREATE TABLE mentions (
  message_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, message_id),
  CONSTRAINT fk_mention_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
