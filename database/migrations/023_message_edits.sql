-- Éditer son message (#200) : date de la dernière modification et anciennes versions (conservées pour l'audit, jamais affichées).
ALTER TABLE conversation_messages ADD COLUMN edited_at DATETIME NULL AFTER created_at;

CREATE TABLE conversation_message_versions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id  INT UNSIGNED NOT NULL,
    body        TEXT NOT NULL,
    saved_at    DATETIME NOT NULL,
    CONSTRAINT fk_message_versions_message FOREIGN KEY (message_id) REFERENCES conversation_messages(id) ON DELETE CASCADE,
    KEY idx_message_versions_message (message_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
