ALTER TABLE password_resets
    ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT 'reset' AFTER user_id,
    ADD KEY idx_password_resets_user_purpose_created (user_id, purpose, created_at);
