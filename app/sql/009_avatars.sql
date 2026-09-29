-- Profile photos: stored privately under uploads/avatars/, shown through avatar.php.
ALTER TABLE users
  ADD COLUMN avatar_path VARCHAR(255) NULL,
  ADD COLUMN avatar_version INT UNSIGNED NULL,          -- changes whenever the photo does (cache-busting)
  ADD COLUMN avatar_source ENUM('telegram','upload') NULL;
