-- Cotisations par personne (#362), livraison 6 : la cotisation est due PAR ADHÉRENT et par mois (décision du propriétaire), pas par groupe.
-- Une écriture de la catégorie « cotisations » peut désigner un compte (`member_user_id`, jamais un nom saisi en texte) et le mois couvert
-- (`period_month`, premier jour du mois) ; les deux vont ensemble et ne concernent que les cotisations (contrôlé par le service). Les comptes
-- ne sont jamais supprimés (anonymisés) : la clé reste valide et l'écriture intacte. L'unicité « un adhérent, un mois » ne peut pas être une clé
-- de la base (une contre-passation, puis une nouvelle saisie, sont légitimes) : elle est vérifiée par le service sous le verrou de l'exercice.
-- `accounting_member_exemptions` : mois où un adhérent est dispensé de cotisation (décision du trésorier), réversible.
ALTER TABLE accounting_entries
    ADD COLUMN member_user_id INT UNSIGNED NULL AFTER reverses_entry_id,
    ADD COLUMN period_month DATE NULL AFTER member_user_id,
    ADD KEY idx_entries_member_month (member_user_id, period_month),
    ADD CONSTRAINT fk_entries_member FOREIGN KEY (member_user_id) REFERENCES users(id),
    ADD CONSTRAINT chk_entries_member_month CHECK ((member_user_id IS NULL) = (period_month IS NULL));

CREATE TABLE accounting_member_exemptions (
    member_user_id     INT UNSIGNED NOT NULL,
    period_month       DATE NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL,
    PRIMARY KEY (member_user_id, period_month),
    CONSTRAINT fk_exemption_member FOREIGN KEY (member_user_id) REFERENCES users(id),
    CONSTRAINT fk_exemption_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
