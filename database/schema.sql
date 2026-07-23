-- Source de vérité complète du schéma. Les fichiers dans migrations/ sont le
-- découpage unitaire appliqué par bin/migrate.php ; ce fichier sert à
-- recréer le schéma en un coup (dev local, tests).

CREATE TABLE users (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email                   VARCHAR(190) NOT NULL,
    password_hash           VARCHAR(255) NOT NULL,
    display_name            VARCHAR(100) NOT NULL,
    role                    ENUM('admin', 'musicien') NOT NULL DEFAULT 'musicien',
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    failed_login_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until            DATETIME NULL,
    last_login_at           DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `groups` (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    genre           VARCHAR(60) NULL,
    color_hex       CHAR(7) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_user (
    group_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    CONSTRAINT fk_group_user_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_user_user  FOREIGN KEY (user_id)  REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE migrations_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration    VARCHAR(190) NOT NULL,
    applied_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_migrations_log_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
