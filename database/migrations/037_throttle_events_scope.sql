-- Limite de connexion par couple (adresse, identifiant) (#441) : chaque évènement d'un couple est rattaché à l'empreinte de l'identifiant
-- (la « portée »), pour qu'un déblocage par un administrateur, qui ne connaît pas l'adresse de la personne, lève aussi le blocage de son
-- couple. Aucune donnée en clair : deux empreintes. NULL pour les évènements sans portée (adresse seule, mot de passe oublié).
ALTER TABLE throttle_events
    ADD COLUMN scope_hash CHAR(64) NULL AFTER subject_hash,
    ADD KEY idx_throttle_events_scope (scope_hash);
