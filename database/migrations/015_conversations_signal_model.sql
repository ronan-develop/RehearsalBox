ALTER TABLE conversations CHANGE subject title VARCHAR(150) NULL;

ALTER TABLE conversation_states
    DROP COLUMN archived,
    ADD COLUMN typing_at DATETIME NULL AFTER last_read_at;

ALTER TABLE conversation_messages
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
