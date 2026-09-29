-- Forwarding: a forwarded copy remembers the original message, so "Forwarded from X" can
-- jump back to it. (forwarded_from already holds the original author's name.)
ALTER TABLE messages ADD COLUMN forwarded_msg_id INT UNSIGNED NULL AFTER forwarded_from;
