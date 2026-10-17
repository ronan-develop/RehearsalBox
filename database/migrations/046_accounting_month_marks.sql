-- Guidage du trésorier (#362), livraison 8 : « Rien à saisir ce mois-ci ». Un mois sans aucun mouvement ne doit pas rester « à faire » pour
-- toujours : le trésorier l'indique, une fois (clé unique exercice + mois). Ce n'est pas une écriture : rien n'entre dans le livre, et le
-- marquage ne se défait pas (une écriture saisie plus tard dans le mois reste possible).
CREATE TABLE accounting_month_marks (
    fiscal_year_id     INT UNSIGNED NOT NULL,
    month              DATE NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL,
    PRIMARY KEY (fiscal_year_id, month),
    CONSTRAINT fk_month_marks_year FOREIGN KEY (fiscal_year_id) REFERENCES accounting_fiscal_years(id),
    CONSTRAINT fk_month_marks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
