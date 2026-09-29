-- The column was spelled the British way; everything else in the app says "color".
-- Written so it is safe on a copy where the rename has already happened.
SET @renamed := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'bible_marks' AND column_name = 'colour');
SET @sql := IF(@renamed > 0,
  'ALTER TABLE bible_marks CHANGE colour color VARCHAR(8) NOT NULL DEFAULT ""',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
