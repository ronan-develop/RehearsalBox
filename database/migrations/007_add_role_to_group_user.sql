ALTER TABLE group_user
    ADD COLUMN role ENUM('gestionnaire', 'membre') NOT NULL DEFAULT 'membre' AFTER user_id;
