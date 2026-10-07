-- Mesures du tableau de bord (#195) : évènements ponctuels (30 jours), compteurs horaires (90 jours) et instantanés de santé
-- (90 jours). AUCUNE donnée personnelle : pas d'e-mail, de texte ni de titre ; l'adresse IP n'est conservée que sous forme
-- d'empreinte HMAC tronquée (secret du serveur, illisible sans lui).
CREATE TABLE metric_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type       VARCHAR(32) NOT NULL,
    route      VARCHAR(120) NOT NULL,
    status     SMALLINT UNSIGNED NULL,
    ip_hash    CHAR(16) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_metric_events_type_date (type, created_at),
    KEY idx_metric_events_date (created_at),
    KEY idx_metric_events_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne par heure, route (motif, sans identifiant) et classe de statut : le polling de la messagerie y est compté en
-- agrégat, jamais une ligne par requête.
CREATE TABLE metric_hourly (
    hour_start       DATETIME NOT NULL,
    route            VARCHAR(120) NOT NULL,
    status_class     CHAR(3) NOT NULL,
    requests         INT UNSIGNED NOT NULL DEFAULT 0,
    duration_total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_max_ms  INT UNSIGNED NOT NULL DEFAULT 0,
    memory_peak_kb   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (hour_start, route, status_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE health_snapshots (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    taken_at          DATETIME NOT NULL,
    disk_free_bytes   BIGINT UNSIGNED NULL,
    db_size_bytes     BIGINT UNSIGNED NULL,
    backup_age_hours  INT UNSIGNED NULL,
    last_cron_at      DATETIME NULL,
    release_marker    VARCHAR(64) NULL,
    php_version       VARCHAR(16) NOT NULL,
    load_1m           DECIMAL(6,2) NULL,
    KEY idx_health_snapshots_date (taken_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
