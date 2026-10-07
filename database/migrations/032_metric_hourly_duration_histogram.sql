-- Mesures de charge (#198) : répartition des durées de requête par tranche, pour estimer la médiane et le 95e centile à partir
-- des agrégats horaires (on ne garde jamais une ligne par requête). Tranches : ≤ 50 ms, ≤ 100, ≤ 250, ≤ 500, ≤ 1000, au-delà.
-- Les lignes déjà collectées gardent 0 partout : leurs centiles sont simplement inconnus.
ALTER TABLE metric_hourly
    ADD COLUMN dur_le_50 INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN dur_le_100 INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN dur_le_250 INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN dur_le_500 INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN dur_le_1000 INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN dur_over_1000 INT UNSIGNED NOT NULL DEFAULT 0;
