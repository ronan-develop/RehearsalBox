-- Sourdine par conversation (#210) : un interrupteur personnel, rangé avec l'état de la personne dans la conversation
-- (dernière lecture, saisie). 0 = les notifications sont actives (comportement existant).
ALTER TABLE conversation_states ADD COLUMN muted TINYINT(1) NOT NULL DEFAULT 0;
