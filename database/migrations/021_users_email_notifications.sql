-- Préférence « recevoir les e-mails de mention » (#178). Activée par défaut ; la personne peut s'en désinscrire dans Mon compte.
ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1;
