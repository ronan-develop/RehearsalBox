-- Titulaires du bureau de l'association (#478) : un poste = un compte + le prénom et nom à imprimer dans les comptes rendus.
-- Modifiable par un administrateur (page /admin/board) ; remplace les clés de configuration `assembly.board*`. Les comptes ne sont jamais
-- supprimés (anonymisés), donc la clé vers `users` ne casse pas. Un même compte ne tient pas deux postes : contrôlé par le service
-- (l'écriture se fait par `INSERT … ON DUPLICATE KEY UPDATE` sur le poste seul).
CREATE TABLE association_board (
    post                VARCHAR(20) NOT NULL PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    full_name           VARCHAR(120) NOT NULL,
    updated_by_user_id  INT UNSIGNED NOT NULL,
    updated_at          DATETIME NOT NULL,
    CONSTRAINT fk_association_board_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_association_board_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
