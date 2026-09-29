-- The daily reading: each day's readings, and a record of what was posted.

CREATE TABLE reading_schedule (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reading_date  DATE NOT NULL,
  position      SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- several rows can share a date
  text          VARCHAR(500) NOT NULL,
  KEY idx_date (reading_date, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per posted day; the primary key makes posting a day twice impossible.
CREATE TABLE reading_posts (
  reading_date        DATE PRIMARY KEY,
  reading_message_id  INT UNSIGNED NULL,
  poll_message_id     INT UNSIGNED NULL,
  posted_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  name   VARCHAR(64) PRIMARY KEY,
  value  TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (name, value) VALUES
  ('daily_reading_enabled', '1'),
  ('daily_reading_time_utc', '05:00'),   -- same as the old bot: midnight Eastern in winter, 1 a.m. in summer
  ('daily_reading_topic', (SELECT COALESCE((SELECT id FROM topics WHERE title = 'Scheduled Reading'), 0))),
  ('daily_reading_user', (SELECT COALESCE((SELECT id FROM users WHERE role = 'system' AND display_name = 'Daily Reading'), 0)));
