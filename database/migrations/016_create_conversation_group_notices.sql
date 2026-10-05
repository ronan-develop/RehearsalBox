CREATE TABLE conversation_group_notices (
    conversation_id  INT UNSIGNED NOT NULL,
    group_id         INT UNSIGNED NOT NULL,
    notified_at      DATETIME NULL,
    reminded_at      DATETIME NULL,
    PRIMARY KEY (conversation_id, group_id),
    CONSTRAINT fk_group_notices_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_notices_group        FOREIGN KEY (group_id)        REFERENCES `groups`(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
