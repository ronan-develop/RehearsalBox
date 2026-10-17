-- Rappels e-mail du trésorier (#362), livraison 8 : un canal par destinataire (trésorier, président), la date du dernier e-mail partie. La
-- réservation de l'envoi est ATOMIQUE (une mise à jour conditionnelle sur `last_sent_at`) : le cron horaire peut tourner deux fois en même
-- temps, un seul message part, et un échec d'envoi remet la date précédente. Aucune adresse, aucun nom, aucun contenu n'est stocké ici.
CREATE TABLE accounting_reminder_notices (
    channel      VARCHAR(20) NOT NULL PRIMARY KEY,
    last_sent_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
