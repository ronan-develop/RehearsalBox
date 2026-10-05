-- Corbeille (#190) : l'initiateur met la conversation à la corbeille (récupérable 30 jours, purge ensuite).
ALTER TABLE conversations
    ADD COLUMN created_by INT UNSIGNED NULL AFTER target_group_id,
    ADD COLUMN deleted_at DATETIME NULL AFTER created_at,
    ADD CONSTRAINT fk_conversations_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD KEY idx_conversations_deleted_at (deleted_at);

-- Existant : l'initiateur est l'auteur du premier message ordinaire.
UPDATE conversations c
SET c.created_by = (
    SELECT m.author_id FROM conversation_messages m
    WHERE m.conversation_id = c.id AND m.is_system = 0
    ORDER BY m.id ASC LIMIT 1
);
