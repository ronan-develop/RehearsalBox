CREATE TABLE recurring_slots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id        INT UNSIGNED NOT NULL,
    weekday         TINYINT UNSIGNED NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recurring_slots_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT chk_recurring_slots_time CHECK (start_time < end_time),
    KEY idx_recurring_slots_weekday (weekday, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
