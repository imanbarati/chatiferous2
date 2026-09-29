-- Cached GIF search results (KLIPY), to stay within the API's rate limit.
CREATE TABLE gif_cache (
  cache_key   CHAR(40) PRIMARY KEY,
  body        MEDIUMTEXT NOT NULL,
  fetched_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
