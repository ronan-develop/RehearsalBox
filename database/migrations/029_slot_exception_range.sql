-- Plage partielle d'une demande de créneau (#263) : « manger un bout » du créneau d'un autre groupe (ex. 18h30–19h sur un créneau
-- 18h30–22h45). NULL = tout le créneau du titulaire (comportement existant). La plage reste comprise dans le créneau du titulaire
-- (vérifié par le service) ; ici, seulement la cohérence : les deux bornes ensemble, début avant fin.
ALTER TABLE slot_exceptions
    ADD COLUMN start_time TIME NULL AFTER occurrence_date,
    ADD COLUMN end_time TIME NULL AFTER start_time,
    ADD CONSTRAINT chk_slot_exceptions_range CHECK (
        (start_time IS NULL AND end_time IS NULL) OR (start_time IS NOT NULL AND end_time IS NOT NULL AND start_time < end_time)
    );
