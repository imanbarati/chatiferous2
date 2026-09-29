-- Unread reactions (as in Telegram): a reaction to someone's message is new to its author until
-- they've seen that message. Earlier and imported reactions count as seen.
ALTER TABLE reactions
  ADD COLUMN author_seen TINYINT(1) NOT NULL DEFAULT 1,
  ADD KEY idx_unseen (author_seen, message_id);
