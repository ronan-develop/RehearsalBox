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
    $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$insertUser = $pdo->prepare(
    'INSERT INTO users (email, password_hash, display_name, role) VALUES (:email, :hash, :name, :role)'
);
$userIds = [];
foreach ([
    ['admin@rehearsalbox.test', 'Admin Local', 'admin'],
    ['alice@rehearsalbox.test', 'Alice', 'musicien'],
    ['bob@rehearsalbox.test', 'Bob', 'musicien'],
    ['chris@rehearsalbox.test', 'Chris', 'musicien'],
] as [$email, $name, $role]) {
    $insertUser->execute(['email' => $email, 'hash' => $passwordHash, 'name' => $name, 'role' => $role]);
    $userIds[$email] = (int) $pdo->lastInsertId();
}

$insertGroup = $pdo->prepare(
    'INSERT INTO `groups` (name, genre, color_hex) VALUES (:name, :genre, :color)'
);
$groupIds = [];
foreach ([
    ['Black Sabbath Tribute', 'metal', '#e63946'],
    ['Dead Kennedys Cover', 'punk', '#f77f00'],
    ['Blackened Sun', 'black metal', '#6a4c93'],
    ['Nebula Sprawl', 'prog rock', '#1982c4'],
    ['Rust Prophet', 'stoner rock', '#8ac926'],
    ['Vacant Riot', 'punk hardcore', '#ff595e'],
    ['Iron Vultures', 'heavy metal', '#ffca3a'],
] as [$name, $genre, $color]) {
    $insertGroup->execute(['name' => $name, 'genre' => $genre, 'color' => $color]);
    $groupIds[$name] = (int) $pdo->lastInsertId();
}

$insertMember = $pdo->prepare('INSERT INTO group_user (group_id, user_id) VALUES (:group_id, :user_id)');
foreach ([
    [$groupIds['Black Sabbath Tribute'], $userIds['alice@rehearsalbox.test']],
    [$groupIds['Black Sabbath Tribute'], $userIds['bob@rehearsalbox.test']],
    [$groupIds['Dead Kennedys Cover'], $userIds['chris@rehearsalbox.test']],
] as [$groupId, $userId]) {
    $insertMember->execute(['group_id' => $groupId, 'user_id' => $userId]);
}

$insertSlot = $pdo->prepare(
    'INSERT INTO recurring_slots (group_id, weekday, start_time, end_time) VALUES (:group_id, :weekday, :start, :end)'
);
$insertSlot->execute(['group_id' => $groupIds['Black Sabbath Tribute'], 'weekday' => 1, 'start' => '18:00:00', 'end' => '20:00:00']);
$insertSlot->execute(['group_id' => $groupIds['Dead Kennedys Cover'], 'weekday' => 3, 'start' => '19:00:00', 'end' => '21:00:00']);
$deadKennedysSlotId = (int) $pdo->lastInsertId();
$insertSlot->execute(['group_id' => $groupIds['Blackened Sun'], 'weekday' => 0, 'start' => '18:00:00', 'end' => '20:00:00']);
$insertSlot->execute(['group_id' => $groupIds['Nebula Sprawl'], 'weekday' => 2, 'start' => '20:00:00', 'end' => '22:30:00']);
$insertSlot->execute(['group_id' => $groupIds['Rust Prophet'], 'weekday' => 4, 'start' => '19:30:00', 'end' => '22:00:00']);
$insertSlot->execute(['group_id' => $groupIds['Vacant Riot'], 'weekday' => 5, 'start' => '14:00:00', 'end' => '17:00:00']);
$insertSlot->execute(['group_id' => $groupIds['Iron Vultures'], 'weekday' => 6, 'start' => '21:00:00', 'end' => '23:30:00']);

$insertException = $pdo->prepare(
    "INSERT INTO slot_exceptions (recurring_slot_id, occurrence_date, status, requested_by_group_id, requested_by_user_id, request_reason)
     VALUES (:slot_id, DATE_ADD(CURDATE(), INTERVAL (10 - DAYOFWEEK(CURDATE())) DAY), 'en_attente', :requested_group, :requested_by, 'Concert samedi, répétition supplémentaire nécessaire')"
);
$insertException->execute([
    'slot_id' => $deadKennedysSlotId,
    'requested_group' => $groupIds['Black Sabbath Tribute'],
    'requested_by' => $userIds['alice@rehearsalbox.test'],
]);

echo "Seed appliqué (mot de passe pour tous les comptes : \"password\").\n";
