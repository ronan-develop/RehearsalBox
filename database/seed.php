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
    'INSERT INTO `groups` (name, genre, color_hex, contact_email) VALUES (:name, :genre, :color, :contactEmail)'
);
$groupIds = [];
foreach ([
    ['Black Sabbath Tribute', 'metal', '#e63946', 'contact+black-sabbath-tribute@rehearsalbox.test'],
    ['Dead Kennedys Cover', 'punk', '#f77f00', 'contact+dead-kennedys-cover@rehearsalbox.test'],
    ['Blackened Sun', 'black metal', '#6a4c93', 'contact+blackened-sun@rehearsalbox.test'],
    ['Nebula Sprawl', 'prog rock', '#1982c4', 'contact+nebula-sprawl@rehearsalbox.test'],
    ['Rust Prophet', 'stoner rock', '#8ac926', 'contact+rust-prophet@rehearsalbox.test'],
    ['Vacant Riot', 'punk hardcore', '#ff595e', 'contact+vacant-riot@rehearsalbox.test'],
    ['Iron Vultures', 'heavy metal', '#ffca3a', 'contact+iron-vultures@rehearsalbox.test'],
] as [$name, $genre, $color, $contactEmail]) {
    $insertGroup->execute(['name' => $name, 'genre' => $genre, 'color' => $color, 'contactEmail' => $contactEmail]);
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
$blackSabbathSlotId = (int) $pdo->lastInsertId();
$insertSlot->execute(['group_id' => $groupIds['Dead Kennedys Cover'], 'weekday' => 3, 'start' => '19:00:00', 'end' => '21:00:00']);
$deadKennedysSlotId = (int) $pdo->lastInsertId();
$insertSlot->execute(['group_id' => $groupIds['Blackened Sun'], 'weekday' => 0, 'start' => '18:00:00', 'end' => '20:00:00']);
$insertSlot->execute(['group_id' => $groupIds['Nebula Sprawl'], 'weekday' => 2, 'start' => '20:00:00', 'end' => '22:30:00']);
$nebulaSprawlSlotId = (int) $pdo->lastInsertId();
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

// #43 : plusieurs demandes en_attente reçues simultanément sur le créneau
// de Black Sabbath Tribute (groupe d'Alice) pour visualiser le deck de
// cartes empilées en dev — sans ça une seule carte "en_attente" ne permet
// pas de constater visuellement le fix de neutralisation des cartes du dessous.
$insertPendingReceivedException = $pdo->prepare(
    "INSERT INTO slot_exceptions (recurring_slot_id, occurrence_date, status, requested_by_group_id, requested_by_user_id, request_reason)
     VALUES (:slot_id, :occurrence_date, 'en_attente', :requested_group, :requested_by, :reason)"
);
foreach ([
    ['Rust Prophet', 'bob@rehearsalbox.test', '+7 days', 'Session d\'enregistrement, besoin du créneau'],
    ['Vacant Riot', 'bob@rehearsalbox.test', '+8 days', null],
    ['Iron Vultures', 'chris@rehearsalbox.test', '+9 days', 'Répétition avant showcase'],
] as [$requestingGroup, $requesterEmail, $offset, $reason]) {
    $insertPendingReceivedException->execute([
        'slot_id' => $blackSabbathSlotId,
        'occurrence_date' => (new DateTimeImmutable($offset))->format('Y-m-d'),
        'requested_group' => $groupIds[$requestingGroup],
        'requested_by' => $userIds[$requesterEmail],
        'reason' => $reason,
    ]);
}

// Créneaux occasionnels (#34) : exceptions déjà acceptées, dont l'occurrence
// tombe dans la semaine en cours (lundi-dimanche) — visibles dans le planning
// avec le label "Occasionnel", sur 2 groupes distincts pour illustrer le tri
// chronologique mélangé avec les créneaux fixes.
$insertAcceptedException = $pdo->prepare(
    "INSERT INTO slot_exceptions (recurring_slot_id, occurrence_date, status, requested_by_group_id, requested_by_user_id, request_reason, responded_by_user_id, responded_at)
     VALUES (:slot_id, :occurrence_date, 'acceptee', :requested_group, :requested_by, :reason, :responded_by, NOW())"
);
$insertAcceptedException->execute([
    'slot_id' => $blackSabbathSlotId,
    'occurrence_date' => (new DateTimeImmutable('monday this week'))->format('Y-m-d'),
    'requested_group' => $groupIds['Rust Prophet'],
    'requested_by' => $userIds['bob@rehearsalbox.test'],
    'reason' => 'Session d\'enregistrement, créneau du titulaire libéré cette semaine',
    'responded_by' => $userIds['alice@rehearsalbox.test'],
]);
$insertAcceptedException->execute([
    'slot_id' => $nebulaSprawlSlotId,
    'occurrence_date' => (new DateTimeImmutable('wednesday this week'))->format('Y-m-d'),
    'requested_group' => $groupIds['Vacant Riot'],
    'requested_by' => $userIds['chris@rehearsalbox.test'],
    'reason' => null,
    'responded_by' => $userIds['bob@rehearsalbox.test'],
]);

// #59 : 5 demandes d'échange déjà acceptées sur le créneau de Black Sabbath
// Tribute, pour reproduire visuellement le deck de cartes empilées
// (--deck-index, ticket #42) avec plusieurs cartes dans la pile.
foreach ([
    ['Dead Kennedys Cover', 'chris@rehearsalbox.test', '-6 days', 'Studio réservé, échange de créneau'],
    ['Blackened Sun', 'bob@rehearsalbox.test', '-5 days', 'Répétition avant showcase'],
    ['Nebula Sprawl', 'chris@rehearsalbox.test', '-4 days', null],
    ['Vacant Riot', 'bob@rehearsalbox.test', '-3 days', 'Enregistrement démo'],
    ['Iron Vultures', 'chris@rehearsalbox.test', '-2 days', null],
] as [$requestingGroup, $requesterEmail, $offset, $reason]) {
    $insertAcceptedException->execute([
        'slot_id' => $blackSabbathSlotId,
        'occurrence_date' => (new DateTimeImmutable($offset))->format('Y-m-d'),
        'requested_group' => $groupIds[$requestingGroup],
        'requested_by' => $userIds[$requesterEmail],
        'reason' => $reason,
        'responded_by' => $userIds['alice@rehearsalbox.test'],
    ]);
}

// Historique de demandes refusées par Alice sur son créneau (Black Sabbath
// Tribute) — complète les 5 acceptées ci-dessus (#59) pour un historique
// réaliste avec les deux issues possibles, visible côté "Reçue" du dashboard.
$insertRefusedException = $pdo->prepare(
    "INSERT INTO slot_exceptions (recurring_slot_id, occurrence_date, status, requested_by_group_id, requested_by_user_id, request_reason, responded_by_user_id, responded_at)
     VALUES (:slot_id, :occurrence_date, 'refusee', :requested_group, :requested_by, :reason, :responded_by, NOW())"
);
foreach ([
    ['Rust Prophet', 'bob@rehearsalbox.test', '-9 days', 'Test matériel avant concert'],
    ['Dead Kennedys Cover', 'chris@rehearsalbox.test', '-8 days', 'Créneau déjà pris par un autre groupe'],
    ['Vacant Riot', 'bob@rehearsalbox.test', '-7 days', null],
] as [$requestingGroup, $requesterEmail, $offset, $reason]) {
    $insertRefusedException->execute([
        'slot_id' => $blackSabbathSlotId,
        'occurrence_date' => (new DateTimeImmutable($offset))->format('Y-m-d'),
        'requested_group' => $groupIds[$requestingGroup],
        'requested_by' => $userIds[$requesterEmail],
        'reason' => $reason,
        'responded_by' => $userIds['alice@rehearsalbox.test'],
    ]);
}

echo "Seed appliqué (mot de passe pour tous les comptes : \"password\").\n";
