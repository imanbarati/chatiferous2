-- Quote-reply: the part of the replied-to message being answered (as in Telegram, up to 1,024
-- characters, and it must appear in that message). Shown in the reply header instead of its start.
ALTER TABLE messages ADD COLUMN quote_text VARCHAR(1024) NULL AFTER reply_to_id;
