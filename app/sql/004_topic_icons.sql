-- Each topic's symbol (an id in web/assets/topic-icons.svg). NULL = the topic's first letter.
ALTER TABLE topics ADD COLUMN icon_key VARCHAR(40) NULL AFTER icon_emoji;
