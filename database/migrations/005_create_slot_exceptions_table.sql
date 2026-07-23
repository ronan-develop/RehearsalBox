CREATE TABLE slot_exceptions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recurring_slot_id     INT UNSIGNED NOT NULL,
    occurrence_date       DATE NOT NULL,
    status                ENUM('liberee', 'revendiquee', 'expiree', 'annulee') NOT NULL DEFAULT 'liberee',
    released_by_user_id   INT UNSIGNED NOT NULL,
    released_reason       VARCHAR(255) NULL,
    claimed_by_group_id   INT UNSIGNED NULL,
    claimed_by_user_id    INT UNSIGNED NULL,
    claimed_at            DATETIME NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slot_exceptions_slot_date (recurring_slot_id, occurrence_date),
    CONSTRAINT fk_slot_exceptions_slot          FOREIGN KEY (recurring_slot_id)  REFERENCES recurring_slots(id) ON DELETE CASCADE,
    CONSTRAINT fk_slot_exceptions_released_by   FOREIGN KEY (released_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_slot_exceptions_claimed_group FOREIGN KEY (claimed_by_group_id) REFERENCES `groups`(id),
    CONSTRAINT fk_slot_exceptions_claimed_user  FOREIGN KEY (claimed_by_user_id)  REFERENCES users(id),
    KEY idx_slot_exceptions_date_status (occurrence_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
