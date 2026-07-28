ALTER TABLE `groups`
    ADD COLUMN lineup JSON NULL AFTER contact_email,
    ADD COLUMN upcoming_shows JSON NULL AFTER lineup;
