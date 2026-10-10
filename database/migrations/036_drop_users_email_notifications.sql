-- #266 : plus de désabonnement général des e-mails. On ne se désabonne que par conversation (sourdine, #210). La colonne
-- users.email_notifications (migration 021) n'est plus lue ni écrite par le code. Vérifié avant la mise en service : aucun compte
-- n'était désabonné en production (donc rien à convertir en sourdine) ; la sauvegarde d'avant déploiement est faite par bin/deploy.sh.
ALTER TABLE users DROP COLUMN email_notifications;
