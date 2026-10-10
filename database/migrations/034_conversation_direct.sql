-- Messages directs (#269) : une conversation entre DEUX PERSONNES, sans groupe. Même modèle que la conversation de groupe
-- (messages, états de lecture, sourdine, corbeille). Une conversation est directe quand ses deux groupes sont NULL et que
-- la paire de personnes est renseignée, ordonnée (la plus petite identité d'abord) : la clé unique garantit UNE conversation
-- par paire, même si deux créations arrivent en même temps. Le compte d'une des deux personnes supprimé, la conversation part.
ALTER TABLE conversations
    MODIFY initiator_group_id INT UNSIGNED NULL,
    MODIFY target_group_id INT UNSIGNED NULL,
    ADD COLUMN direct_low_user_id INT UNSIGNED NULL AFTER target_group_id,
    ADD COLUMN direct_high_user_id INT UNSIGNED NULL AFTER direct_low_user_id,
    ADD CONSTRAINT fk_conversations_direct_low FOREIGN KEY (direct_low_user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_conversations_direct_high FOREIGN KEY (direct_high_user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD UNIQUE KEY uq_conversations_direct_pair (direct_low_user_id, direct_high_user_id),
    ADD CONSTRAINT chk_conversations_kind CHECK (
        (initiator_group_id IS NOT NULL AND target_group_id IS NOT NULL AND direct_low_user_id IS NULL AND direct_high_user_id IS NULL)
        OR (initiator_group_id IS NULL AND target_group_id IS NULL AND direct_low_user_id IS NOT NULL AND direct_high_user_id IS NOT NULL
            AND direct_low_user_id < direct_high_user_id)
    );
