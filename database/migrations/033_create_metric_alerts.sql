-- Alertes du tableau de bord (#199) : la date du dernier envoi de chaque type d'alerte, pour ne jamais en renvoyer une avant le
-- délai minimum (anti-bruit). Aucune donnée personnelle.
CREATE TABLE metric_alerts (
    alert_key    VARCHAR(40) NOT NULL PRIMARY KEY,
    last_sent_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
