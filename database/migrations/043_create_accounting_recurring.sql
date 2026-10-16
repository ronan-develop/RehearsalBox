-- Écritures récurrentes (#362), livraison 5 : modèles (loyer, électricité, assurance, frais bancaires…) et échéances traitées. Un modèle
-- ne crée JAMAIS d'écriture seul : chaque échéance arrive dans la liste « À confirmer » et c'est la confirmation (ou l'abandon) du
-- trésorier qui la décide. `amount_cents` NULL = montant variable (électricité), à saisir à chaque échéance. Une échéance est décidée UNE
-- fois (clé unique modèle + mois) : le double clic et deux onglets ne créent jamais deux écritures. Les modèles se désactivent, ne se
-- suppriment pas (l'historique des décisions reste lisible).
CREATE TABLE accounting_recurring_templates (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label              VARCHAR(150) NOT NULL,
    direction          ENUM('recette', 'depense') NOT NULL,
    category           VARCHAR(30) NOT NULL,
    payment_method     ENUM('especes', 'virement', 'cheque', 'carte') NOT NULL,
    amount_cents       BIGINT UNSIGNED NULL,
    voucher_ref        VARCHAR(100) NULL,
    periodicity        ENUM('mensuelle', 'trimestrielle', 'annuelle') NOT NULL,
    due_day            TINYINT UNSIGNED NOT NULL,
    first_month        DATE NOT NULL,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL,
    CONSTRAINT chk_recurring_day CHECK (due_day BETWEEN 1 AND 28),
    CONSTRAINT chk_recurring_amount CHECK (amount_cents IS NULL OR amount_cents > 0),
    CONSTRAINT fk_recurring_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_recurring_occurrences (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_id        INT UNSIGNED NOT NULL,
    period             DATE NOT NULL,
    status             ENUM('confirmee', 'ignoree') NOT NULL,
    entry_id           INT UNSIGNED NULL,
    decided_by_user_id INT UNSIGNED NOT NULL,
    decided_at         DATETIME NOT NULL,
    UNIQUE KEY uq_recurring_occurrence (template_id, period),
    UNIQUE KEY uq_recurring_entry (entry_id),
    CONSTRAINT fk_occurrence_template FOREIGN KEY (template_id) REFERENCES accounting_recurring_templates(id),
    CONSTRAINT fk_occurrence_entry FOREIGN KEY (entry_id) REFERENCES accounting_entries(id),
    CONSTRAINT fk_occurrence_decider FOREIGN KEY (decided_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
