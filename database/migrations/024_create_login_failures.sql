-- Échecs de connexion par adresse (#218) : limite par IP en plus du verrou par compte. Aucune adresse en clair :
-- seule l'empreinte SHA-256 est stockée, et les lignes de plus de 24 h sont purgées au fil de l'eau.
CREATE TABLE login_failures (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash    CHAR(64) NOT NULL,
    failed_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_failures_ip (ip_hash, failed_at),
    KEY idx_login_failures_at (failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
