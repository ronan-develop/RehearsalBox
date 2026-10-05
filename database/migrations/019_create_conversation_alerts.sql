-- Avis dans l'application (#190) : prévient les participants qu'une conversation a été mise à la corbeille ou restaurée.
-- Ni push ni e-mail. Le titre n'est jamais recopié : l'avis ne porte que les noms des groupes.
CREATE TABLE conversation_alerts (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    conversation_id  INT UNSIGNED NULL,
    kind             VARCHAR(20) NOT NULL,
    label            VARCHAR(255) NOT NULL,
    created_at       DATETIME NOT NULL,
    dismissed_at     DATETIME NULL,
    CONSTRAINT fk_conversation_alerts_user         FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_conversation_alerts_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    KEY idx_conversation_alerts_user (user_id, dismissed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
