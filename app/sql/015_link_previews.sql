-- Link previews: each linked page is fetched once by the server; its title, description and
-- image (copied into uploads/previews/) are kept here and shown under messages with that link.
CREATE TABLE link_previews (
  url_hash     CHAR(64) PRIMARY KEY,           -- sha256 of the normalised URL
  url          VARCHAR(2048) NOT NULL,
  status       ENUM('ok','none') NOT NULL,     -- none: nothing usable (retried after a day)
  site         VARCHAR(100) NULL,
  title        VARCHAR(300) NULL,
  description  VARCHAR(500) NULL,
  image_path   VARCHAR(255) NULL,
  image_w      SMALLINT UNSIGNED NULL,
  image_h      SMALLINT UNSIGNED NULL,
  fetched_by   INT UNSIGNED NULL,
  fetched_at   DATETIME NOT NULL,
  KEY idx_by (fetched_by, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
