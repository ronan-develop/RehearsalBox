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
