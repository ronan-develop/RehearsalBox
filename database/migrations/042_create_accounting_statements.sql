-- Relevés de compte (#362), livraison 4 : pièces archivées d'un exercice (PDF, JPEG, PNG), jamais modifiées ni supprimées depuis
-- l'interface (conservation 10 ans). Un relevé déposé par erreur est REMPLACÉ par un nouveau (`replaced_by_id`) tant que l'exercice est
-- ouvert. Le fichier vit hors de la racine web sous un nom aléatoire (`stored_name`, jamais dérivé du nom d'origine) ; `sha256` détecte un
-- doublon et prouve l'intégrité. Aucun IBAN ni coordonnée bancaire n'est saisi ni stocké en base : seuls le solde final et la période.
CREATE TABLE accounting_statements (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fiscal_year_id        INT UNSIGNED NOT NULL,
    period_start          DATE NOT NULL,
    period_end            DATE NOT NULL,
    closing_balance_cents BIGINT NULL,
    original_name         VARCHAR(255) NOT NULL,
    stored_name           CHAR(32) NOT NULL,
    mime                  ENUM('application/pdf', 'image/jpeg', 'image/png') NOT NULL,
    size_bytes            INT UNSIGNED NOT NULL,
    sha256                CHAR(64) NOT NULL,
    replaced_by_id        INT UNSIGNED NULL,
    uploaded_by_user_id   INT UNSIGNED NOT NULL,
    uploaded_at           DATETIME NOT NULL,
    UNIQUE KEY uq_statements_stored (stored_name),
    UNIQUE KEY uq_statements_year_hash (fiscal_year_id, sha256),
    KEY idx_statements_year_period (fiscal_year_id, period_start),
    CONSTRAINT chk_statements_period CHECK (period_end >= period_start),
    CONSTRAINT fk_statements_year FOREIGN KEY (fiscal_year_id) REFERENCES accounting_fiscal_years(id),
    CONSTRAINT fk_statements_replaced FOREIGN KEY (replaced_by_id) REFERENCES accounting_statements(id),
    CONSTRAINT fk_statements_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
