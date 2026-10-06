-- Réservations libres du local (#263, partie 2) : un groupe réserve une plage où aucun créneau fixe n'existe, un administrateur
-- valide. Une réservation en attente ou validée bloque sa plage ; refus et annulation la libèrent. Le groupe emporte ses
-- réservations avec lui à sa suppression (comme ses demandes de créneau, migration 027).
CREATE TABLE free_slot_bookings (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id           INT UNSIGNED NOT NULL,
    booked_by_user_id  INT UNSIGNED NOT NULL,
    booking_date       DATE NOT NULL,
    start_time         TIME NOT NULL,
    end_time           TIME NOT NULL,
    status             ENUM('en_attente', 'validee', 'refusee', 'annulee') NOT NULL DEFAULT 'en_attente',
    reason             VARCHAR(255) NULL,
    decision_note      VARCHAR(255) NULL,
    decided_by_user_id INT UNSIGNED NULL,
    decided_at         DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_free_slot_bookings_range CHECK (start_time < end_time),
    CONSTRAINT fk_free_slot_bookings_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_free_slot_bookings_user FOREIGN KEY (booked_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_free_slot_bookings_decider FOREIGN KEY (decided_by_user_id) REFERENCES users(id),
    KEY idx_free_slot_bookings_date_status (booking_date, status),
    KEY idx_free_slot_bookings_group (group_id, booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
