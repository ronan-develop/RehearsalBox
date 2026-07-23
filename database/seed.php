<?php

declare(strict_types=1);

// Jeu de données de démo pour le dev local. Tous les comptes ont pour mot de
// passe "password" — le hash est généré à l'exécution (pas de hash figé en
// dur dans le code source, cf. plan §10.8 / bonne pratique anti-secret).
//
// Usage : php database/seed.php

require __DIR__ . '/../vendor/autoload.php';

use App\Database\ConnectionFactory;

$config = require __DIR__ . '/../config/config.php';
$pdo = (new ConnectionFactory($config['db']))->create();

$passwordHash = password_hash('password', PASSWORD_DEFAULT);

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['slot_exceptions', 'recurring_slots', 'group_user', 'groups', 'users'] as $table) {
    $pdo->exec("DELETE FROM `{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$insertUser = $pdo->prepare(
    'INSERT INTO users (email, password_hash, display_name, role) VALUES (:email, :hash, :name, :role)'
);
foreach ([
    ['admin@rehearsalbox.test', 'Admin Local', 'admin'],
    ['alice@rehearsalbox.test', 'Alice', 'musicien'],
    ['bob@rehearsalbox.test', 'Bob', 'musicien'],
    ['chris@rehearsalbox.test', 'Chris', 'musicien'],
] as [$email, $name, $role]) {
    $insertUser->execute(['email' => $email, 'hash' => $passwordHash, 'name' => $name, 'role' => $role]);
}

$insertGroup = $pdo->prepare(
    'INSERT INTO `groups` (name, genre, color_hex) VALUES (:name, :genre, :color)'
);
foreach ([
    ['Black Sabbath Tribute', 'metal', '#e63946'],
    ['Dead Kennedys Cover', 'punk', '#f77f00'],
] as [$name, $genre, $color]) {
    $insertGroup->execute(['name' => $name, 'genre' => $genre, 'color' => $color]);
}

$pdo->exec('INSERT INTO group_user (group_id, user_id) VALUES (1, 2), (1, 3), (2, 4)');

$insertSlot = $pdo->prepare(
    'INSERT INTO recurring_slots (group_id, weekday, start_time, end_time) VALUES (:group_id, :weekday, :start, :end)'
);
$insertSlot->execute(['group_id' => 1, 'weekday' => 1, 'start' => '18:00:00', 'end' => '20:00:00']);
$insertSlot->execute(['group_id' => 2, 'weekday' => 3, 'start' => '19:00:00', 'end' => '21:00:00']);

$pdo->exec(
    "INSERT INTO slot_exceptions (recurring_slot_id, occurrence_date, status, released_by_user_id, released_reason)
     VALUES (1, DATE_ADD(CURDATE(), INTERVAL (9 - DAYOFWEEK(CURDATE())) DAY), 'liberee', 2, 'Tournée annulée, créneau libre')"
);

echo "Seed appliqué (mot de passe pour tous les comptes : \"password\").\n";
