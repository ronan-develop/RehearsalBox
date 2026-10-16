-- Comptabilité de l'association (#362), livraison 1 : exercices et écritures. Montants en centimes entiers (jamais de flottant).
-- Une écriture est IMMUABLE : le dépôt n'a ni modification ni suppression ; une erreur se corrige par contre-passation (nouvelle ligne
-- de sens inverse, liée à l'originale, UNIQUE : une écriture ne s'annule qu'une fois). Le gel d'un exercice clos ne repose sur aucun
-- trigger (privilège SUPER refusé en mutualisé) : toute écriture se fait sous le verrou de la ligne d'exercice (SELECT … FOR UPDATE) et
-- conditionne `status = 'ouvert'`. `next_entry_number` est lu et incrémenté sous ce même verrou : numérotation sans trou ni doublon.
-- Les comptes ne sont jamais supprimés (anonymisés), donc les clés vers `users` ne cassent pas.
CREATE TABLE accounting_fiscal_years (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label                 VARCHAR(60) NOT NULL,
    starts_on             DATE NOT NULL,
    ends_on               DATE NOT NULL,
    status                ENUM('ouvert', 'cloture', 'approuve') NOT NULL DEFAULT 'ouvert',
    opening_balance_cents BIGINT NOT NULL DEFAULT 0,
    closing_balance_cents BIGINT NULL,
    next_entry_number     INT UNSIGNED NOT NULL DEFAULT 1,
    approved_on           DATE NULL,
    revision              INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id    INT UNSIGNED NOT NULL,
    created_at            DATETIME NOT NULL,
    closed_by_user_id     INT UNSIGNED NULL,
    closed_at             DATETIME NULL,
    approved_by_user_id   INT UNSIGNED NULL,
    approved_at           DATETIME NULL,
    UNIQUE KEY uq_fiscal_years_label (label),
    KEY idx_fiscal_years_period (starts_on, ends_on),
    CONSTRAINT chk_fiscal_years_period CHECK (ends_on > starts_on),
    CONSTRAINT fk_fiscal_years_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_fiscal_years_closer FOREIGN KEY (closed_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_fiscal_years_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_entries (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fiscal_year_id     INT UNSIGNED NOT NULL,
    entry_number       INT UNSIGNED NOT NULL,
    occurred_on        DATE NOT NULL,
    label              VARCHAR(150) NOT NULL,
    amount_cents       BIGINT UNSIGNED NOT NULL,
    direction          ENUM('recette', 'depense') NOT NULL,
    category           VARCHAR(30) NOT NULL,
    payment_method     ENUM('especes', 'virement', 'cheque', 'carte') NOT NULL,
    voucher_ref        VARCHAR(100) NULL,
    note               VARCHAR(255) NULL,
    reverses_entry_id  INT UNSIGNED NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL,
    UNIQUE KEY uq_entries_year_number (fiscal_year_id, entry_number),
    UNIQUE KEY uq_entries_reverses (reverses_entry_id),
    KEY idx_entries_year_date (fiscal_year_id, occurred_on),
    CONSTRAINT chk_entries_amount CHECK (amount_cents > 0),
    CONSTRAINT fk_entries_year FOREIGN KEY (fiscal_year_id) REFERENCES accounting_fiscal_years(id),
    CONSTRAINT fk_entries_reverses FOREIGN KEY (reverses_entry_id) REFERENCES accounting_entries(id),
    CONSTRAINT fk_entries_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
