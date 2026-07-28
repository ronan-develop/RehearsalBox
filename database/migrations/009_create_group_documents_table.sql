CREATE TABLE group_documents (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id            INT UNSIGNED NOT NULL,
    original_name       VARCHAR(255) NOT NULL,
    stored_name         VARCHAR(64) NOT NULL,
    mime_type           VARCHAR(100) NOT NULL,
    size_bytes          INT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_group_documents_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_documents_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_group_documents_stored_name (stored_name),
    KEY idx_group_documents_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
