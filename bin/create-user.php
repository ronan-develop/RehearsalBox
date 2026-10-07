<?php

declare(strict_types=1);

// Usage : php bin/create-user.php <email> <nom> <admin|musicien>
//   Sans RB_USER_PASSWORD : compte SANS mot de passe connu (secret aléatoire inutilisable) ;
//   l'utilisateur choisit le sien via « Mot de passe oublié » (#138). Cas normal.
//   Avec RB_USER_PASSWORD='...' : compte avec ce mot de passe provisoire (premier admin).
// Le mot de passe est lu dans l'environnement, jamais en argument (visible dans `ps`).

require __DIR__ . '/../vendor/autoload.php';

use App\Database\ConnectionFactory;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Security\NativePasswordHasher;
use App\Account\Security\PasswordPolicy;
use App\Account\Service\UserProvisioningService;

$config = require __DIR__ . '/../config/config.php';

[$script, $email, $displayName, $roleName] = $argv + [null, null, null, null];
$password = getenv('RB_USER_PASSWORD');
$role = $roleName === null ? null : UserRole::tryFrom($roleName);

if ($email === null || $displayName === null || $role === null) {
    fwrite(STDERR, "Usage : [RB_USER_PASSWORD='...'] php bin/create-user.php <email> <nom> <admin|musicien>\n");
    exit(2);
}

$pdo = (new ConnectionFactory($config['db']))->create();
$service = new UserProvisioningService(new MysqlUserRepository($pdo), new NativePasswordHasher(), new PasswordPolicy());

try {
    $user = $password === false
        ? $service->createWithoutPassword($email, $displayName, $role)
        : $service->create($email, $displayName, $role, $password);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Compte créé : {$user->email()} ({$user->role()->value})\n";
