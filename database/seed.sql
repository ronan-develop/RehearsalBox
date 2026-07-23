-- Jeu de données de démo pour le dev local. Tous les comptes ont pour mot
-- de passe "password" (hash password_hash générique ci-dessous).

INSERT INTO users (email, password_hash, display_name, role) VALUES
    ('admin@rehearsalbox.test',   '$2y$12$jbj0Ij5LRbaDeW1e57uyWOF9pB7xmGhvnQC/AHqL2qJUE0qwuMR/q', 'Admin Local', 'admin'),
    ('alice@rehearsalbox.test',   '$2y$12$jbj0Ij5LRbaDeW1e57uyWOF9pB7xmGhvnQC/AHqL2qJUE0qwuMR/q', 'Alice',       'musicien'),
    ('bob@rehearsalbox.test',     '$2y$12$jbj0Ij5LRbaDeW1e57uyWOF9pB7xmGhvnQC/AHqL2qJUE0qwuMR/q', 'Bob',         'musicien'),
    ('chris@rehearsalbox.test',   '$2y$12$jbj0Ij5LRbaDeW1e57uyWOF9pB7xmGhvnQC/AHqL2qJUE0qwuMR/q', 'Chris',       'musicien');

INSERT INTO `groups` (name, genre, color_hex) VALUES
    ('Black Sabbath Tribute', 'metal', '#e63946'),
    ('Dead Kennedys Cover',   'punk',  '#f77f00');

INSERT INTO group_user (group_id, user_id) VALUES
    (1, 2), -- Alice dans Black Sabbath Tribute
    (1, 3), -- Bob dans Black Sabbath Tribute
    (2, 4); -- Chris dans Dead Kennedys Cover

INSERT INTO recurring_slots (group_id, weekday, start_time, end_time) VALUES
    (1, 1, '18:00:00', '20:00:00'), -- mardi 18h-20h
    (2, 3, '19:00:00', '21:00:00'); -- jeudi 19h-21h

INSERT INTO slot_exceptions (recurring_slot_id, occurrence_date, status, released_by_user_id, released_reason) VALUES
    (1, DATE_ADD(CURDATE(), INTERVAL (9 - DAYOFWEEK(CURDATE())) DAY), 'liberee', 2, 'Tournée annulée, créneau libre');
