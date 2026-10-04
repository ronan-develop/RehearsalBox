<?php

declare(strict_types=1);

// Usage : RB_USER_PASSWORD='...' php bin/create-user.php <email> <nom> <admin|musicien>
// Le mot de passe est lu dans l'environnement, jamais en argument (visible dans `ps`).

require __DIR__ . '/../vendor/autoload.php';

use App\Database\ConnectionFactory;
use App\Entity\Enum\UserRole;
use App\Repository\MysqlUserRepository;
use App\Security\NativePasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\UserProvisioningService;

$config = require __DIR__ . '/../config/config.php';

[$script, $email, $displayName, $roleName] = $argv + [null, null, null, null];
$password = getenv('RB_USER_PASSWORD');
$role = $roleName === null ? null : UserRole::tryFrom($roleName);

if ($email === null || $displayName === null || $role === null || $password === false) {
    fwrite(STDERR, "Usage : RB_USER_PASSWORD='...' php bin/create-user.php <email> <nom> <admin|musicien>\n");
    exit(2);
}

$pdo = (new ConnectionFactory($config['db']))->create();
$service = new UserProvisioningService(new MysqlUserRepository($pdo), new NativePasswordHasher(), new PasswordPolicy());

try {
    $user = $service->create($email, $displayName, $role, $password);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Compte créé : {$user->email()} ({$user->role()->value})\n";
