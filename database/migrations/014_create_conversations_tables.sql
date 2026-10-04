CREATE TABLE conversations (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    initiator_group_id  INT UNSIGNED NOT NULL,
    target_group_id     INT UNSIGNED NOT NULL,
    subject             VARCHAR(150) NOT NULL,
    created_at          DATETIME NOT NULL,
    CONSTRAINT fk_conversations_initiator FOREIGN KEY (initiator_group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_target    FOREIGN KEY (target_group_id)    REFERENCES `groups`(id) ON DELETE CASCADE,
    KEY idx_conversations_initiator (initiator_group_id),
    KEY idx_conversations_target (target_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_messages (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id  INT UNSIGNED NOT NULL,
    author_id        INT UNSIGNED NOT NULL,
    body             TEXT NOT NULL,
    created_at       DATETIME NOT NULL,
    CONSTRAINT fk_conversation_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_messages_author       FOREIGN KEY (author_id)       REFERENCES users(id)         ON DELETE CASCADE,
    KEY idx_conversation_messages_conversation (conversation_id, id),
    KEY idx_conversation_messages_author_created (author_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_states (
    conversation_id  INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    last_read_at     DATETIME NULL,
    archived         TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_conversation_states_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_states_user         FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
