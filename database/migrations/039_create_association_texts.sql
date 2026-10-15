-- Textes de l'association (#472) : statuts, règlement intérieur… Un texte = un titre unique (insensible à la casse) + un corps, modifiable
-- sur place par un administrateur. `revision` protège de deux administrateurs qui modifient le même texte (UPDATE … WHERE revision = ?).
-- Les comptes ne sont jamais supprimés (anonymisés), donc les clés vers `users` ne cassent pas.
CREATE TABLE association_texts (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title               VARCHAR(150) NOT NULL,
    body                MEDIUMTEXT NOT NULL,
    revision            INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL,
    updated_by_user_id  INT UNSIGNED NOT NULL,
    updated_at          DATETIME NOT NULL,
    UNIQUE KEY uq_association_texts_title (title),
    CONSTRAINT fk_association_texts_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_association_texts_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
