-- Limite par adresse (#219) : la table des échecs de connexion (024) sert aussi à d'autres routes sensibles (mot de passe
-- oublié). Elle devient générique : une empreinte du « sujet » (adresse + étiquette de la limite) et la date de l'évènement.
-- Aucune adresse en clair, lignes purgées au fil de l'eau (24 h).
ALTER TABLE login_failures
    DROP INDEX idx_login_failures_ip,
    DROP INDEX idx_login_failures_at,
    CHANGE ip_hash subject_hash CHAR(64) NOT NULL,
    CHANGE failed_at occurred_at DATETIME NOT NULL,
    ADD KEY idx_throttle_events_subject (subject_hash, occurred_at),
    ADD KEY idx_throttle_events_at (occurred_at),
    RENAME TO throttle_events;
