-- Observabilité de l'envoi (#502) : heure à laquelle le transport (Exim) a ACCEPTÉ le mail qui porte ce jeton. NULL = rien n'est parti
-- (échec d'envoi, ou jeton antérieur à cette migration). Ne dit pas que le destinataire l'a reçu : seulement que la perte, s'il y en a
-- une, se produit après notre serveur. Aucune adresse ni aucun jeton en clair n'est stocké ici.
ALTER TABLE password_resets
    ADD COLUMN mail_sent_at DATETIME NULL AFTER created_at;
