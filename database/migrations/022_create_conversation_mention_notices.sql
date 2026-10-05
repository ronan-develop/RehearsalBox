-- E-mails de mention (#178) : un enregistrement par (conversation, personne mentionnée) = date du dernier e-mail,
-- auteur de la mention (plafond par auteur et par heure) et date de la relance éventuelle. Aucun contenu, aucune adresse.
CREATE TABLE conversation_mention_notices (
    conversation_id  INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    notified_at      DATETIME NOT NULL,
    notified_by      INT UNSIGNED NULL,
    reminded_at      DATETIME NULL,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_mention_notices_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_mention_notices_user         FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_mention_notices_by           FOREIGN KEY (notified_by)     REFERENCES users(id)         ON DELETE SET NULL,
    KEY idx_mention_notices_by (notified_by, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
