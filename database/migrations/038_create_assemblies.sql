-- Comptes rendus d'assemblée générale (#361). Une ligne par version : un compte rendu validé est figé, une correction crée une
-- nouvelle ligne (previous_id) en brouillon. Les noms sont recopiés à la saisie (archive : un compte renommé ou anonymisé ne
-- modifie pas un compte rendu validé). Votes anonymes : seulement des totaux, aucun lien avec une personne.
CREATE TABLE assemblies (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind                 ENUM('ordinaire', 'extraordinaire') NOT NULL,
    held_on              DATE NOT NULL,
    location             VARCHAR(200) NULL,
    opened_at            TIME NULL,
    closed_at            TIME NULL,
    quorum_reached       TINYINT(1) NULL,
    moral_report         TEXT NULL,
    financial_report     TEXT NULL,
    other_business       TEXT NULL,
    status               ENUM('brouillon', 'valide') NOT NULL DEFAULT 'brouillon',
    version              SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    previous_id          INT UNSIGNED NULL,
    correction_reason    VARCHAR(255) NULL,
    revision             INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id   INT UNSIGNED NOT NULL,
    created_at           DATETIME NOT NULL,
    updated_at           DATETIME NOT NULL,
    validated_by_user_id INT UNSIGNED NULL,
    validated_at         DATETIME NULL,
    CONSTRAINT chk_assemblies_validation CHECK (
        (status = 'brouillon' AND validated_at IS NULL AND validated_by_user_id IS NULL)
        OR (status = 'valide' AND validated_at IS NOT NULL AND validated_by_user_id IS NOT NULL)
    ),
    CONSTRAINT chk_assemblies_hours CHECK (closed_at IS NULL OR opened_at IS NULL OR opened_at < closed_at),
    CONSTRAINT chk_assemblies_version CHECK ((version = 1) = (previous_id IS NULL)),
    CONSTRAINT fk_assemblies_previous FOREIGN KEY (previous_id) REFERENCES assemblies(id),
    CONSTRAINT fk_assemblies_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_assemblies_validator FOREIGN KEY (validated_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_assemblies_previous (previous_id),
    KEY idx_assemblies_held_on (held_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assembly_board_members (
    assembly_id INT UNSIGNED NOT NULL,
    post        ENUM('president', 'tresorier', 'secretaire') NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    full_name   VARCHAR(120) NOT NULL,
    PRIMARY KEY (assembly_id, post),
    UNIQUE KEY uq_assembly_board_user (assembly_id, user_id),
    CONSTRAINT fk_assembly_board_assembly FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
    CONSTRAINT fk_assembly_board_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assembly_attendees (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    assembly_id  INT UNSIGNED NOT NULL,
    position     SMALLINT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NULL,
    full_name    VARCHAR(120) NOT NULL,
    attendance   ENUM('present', 'excuse', 'represente') NOT NULL,
    proxy_holder VARCHAR(120) NULL,
    UNIQUE KEY uq_assembly_attendees_position (assembly_id, position),
    UNIQUE KEY uq_assembly_attendees_user (assembly_id, user_id),
    CONSTRAINT chk_assembly_attendees_proxy CHECK ((attendance = 'represente') = (proxy_holder IS NOT NULL)),
    CONSTRAINT fk_assembly_attendees_assembly FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE,
    CONSTRAINT fk_assembly_attendees_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assembly_agenda_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    assembly_id INT UNSIGNED NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    discussion  TEXT NULL,
    UNIQUE KEY uq_assembly_agenda_position (assembly_id, position),
    CONSTRAINT fk_assembly_agenda_assembly FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assembly_resolutions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    assembly_id   INT UNSIGNED NOT NULL,
    position      SMALLINT UNSIGNED NOT NULL,
    text          TEXT NOT NULL,
    votes_for     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    votes_against SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    votes_abstain SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    outcome       ENUM('adoptee', 'rejetee') NULL,
    UNIQUE KEY uq_assembly_resolutions_position (assembly_id, position),
    CONSTRAINT fk_assembly_resolutions_assembly FOREIGN KEY (assembly_id) REFERENCES assemblies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
