-- Import du CSV de la banque (#362), livraison 7. RIEN n'entre dans le livre ici : ces tables ne sont qu'une salle d'attente. Une ligne attend
-- la relecture du trésorier ; seule sa validation (service d'écriture normal) crée une écriture. Les libellés bancaires contiennent des noms
-- de tiers et parfois des IBAN : les lignes sont PURGÉES (30 jours au plus). Seules les empreintes (sans contenu) restent, pour qu'une même
-- ligne importée deux fois n'apparaisse qu'une fois. Aucun identifiant bancaire n'est jamais saisi ni stocké.
-- `accounting_import_profile` : la dernière disposition de colonnes utilisée (une seule ligne), proposée à l'import suivant.
CREATE TABLE accounting_import_profile (
    id                TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    delimiter_char    VARCHAR(1) NOT NULL,
    encoding          VARCHAR(12) NOT NULL,
    skip_rows         TINYINT UNSIGNED NOT NULL,
    date_column       TINYINT UNSIGNED NOT NULL,
    label_column      TINYINT UNSIGNED NOT NULL,
    amount_mode       ENUM('signed', 'split') NOT NULL,
    amount_column     TINYINT UNSIGNED NOT NULL,
    debit_column      TINYINT UNSIGNED NOT NULL,
    credit_column     TINYINT UNSIGNED NOT NULL,
    decimal_separator CHAR(1) NOT NULL,
    date_format       VARCHAR(10) NOT NULL,
    updated_by_user_id INT UNSIGNED NOT NULL,
    updated_at        DATETIME NOT NULL,
    CONSTRAINT chk_import_profile_single CHECK (id = 1),
    CONSTRAINT fk_import_profile_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_import_batches (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    file_sha256       CHAR(64) NOT NULL,
    staged_count      INT UNSIGNED NOT NULL,
    duplicate_count   INT UNSIGNED NOT NULL,
    error_count       INT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    uploaded_at       DATETIME NOT NULL,
    UNIQUE KEY uq_import_batches_file (file_sha256),
    CONSTRAINT fk_import_batches_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_import_lines (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_id      INT UNSIGNED NOT NULL,
    record_number INT UNSIGNED NOT NULL,
    occurred_on   DATE NOT NULL,
    label         VARCHAR(150) NOT NULL,
    amount_cents  BIGINT UNSIGNED NOT NULL,
    direction     ENUM('recette', 'depense') NOT NULL,
    status        ENUM('en_attente', 'validee', 'ecartee') NOT NULL DEFAULT 'en_attente',
    entry_id      INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL,
    decided_at    DATETIME NULL,
    KEY idx_import_lines_status (status, occurred_on, id),
    KEY idx_import_lines_created (created_at),
    CONSTRAINT chk_import_lines_amount CHECK (amount_cents > 0),
    CONSTRAINT fk_import_lines_batch FOREIGN KEY (batch_id) REFERENCES accounting_import_batches(id),
    CONSTRAINT fk_import_lines_entry FOREIGN KEY (entry_id) REFERENCES accounting_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_import_hashes (
    line_hash  CHAR(64) NOT NULL PRIMARY KEY,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mémoire des libellés : une RÈGLE EXPLICITE, créée seulement quand le trésorier coche « Retenir pour la prochaine fois » en validant une ligne
-- (jamais d'apprentissage opaque). Un libellé normalisé qui CONTIENT le motif reçoit ce sens, cette catégorie et ce moyen de paiement ; en cas
-- de doute (deux règles contradictoires de même longueur), la ligne reste « à catégoriser ». Visibles, modifiables et supprimables sur une page.
CREATE TABLE accounting_label_rules (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pattern            VARCHAR(60) NOT NULL,
    direction          ENUM('recette', 'depense') NOT NULL,
    category           VARCHAR(30) NOT NULL,
    payment_method     ENUM('especes', 'virement', 'cheque', 'carte') NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL,
    UNIQUE KEY uq_label_rules_pattern (pattern, direction),
    CONSTRAINT fk_label_rules_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
