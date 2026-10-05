-- Mentions (#178) : taguer un membre du site dans une conversation. Un membre extérieur aux deux groupes devient
-- « invité » : il accède à CETTE conversation seulement. La mention est enregistrée par identifiant (les noms ne sont
-- pas uniques) ; `label` garde le texte « @Nom » tel qu'il a été inséré, pour le surligner dans le fil.
CREATE TABLE conversation_guests (
    conversation_id  INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    added_by         INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_conversation_guests_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_guests_user         FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_conversation_guests_added_by     FOREIGN KEY (added_by)        REFERENCES users(id)         ON DELETE SET NULL,
    KEY idx_conversation_guests_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_mentions (
    message_id  INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    label       VARCHAR(160) NOT NULL,
    PRIMARY KEY (message_id, user_id),
    CONSTRAINT fk_message_mentions_message FOREIGN KEY (message_id) REFERENCES conversation_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_mentions_user    FOREIGN KEY (user_id)    REFERENCES users(id)                 ON DELETE CASCADE,
    KEY idx_message_mentions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
