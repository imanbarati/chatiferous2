-- Full-text search over message text and poll questions.
ALTER TABLE messages ADD FULLTEXT KEY ft_text (text);
ALTER TABLE polls ADD FULLTEXT KEY ft_question (question);
