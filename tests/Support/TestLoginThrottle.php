<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Account\Repository\MysqlThrottleEventRepository;
use App\Account\Service\Throttle\SubjectThrottle;
use App\Account\Service\Throttle\LoginThrottle;

/** Blocage de connexion des tests (#236) : mêmes limites qu'en production (20 par adresse, 5 par identifiant, fenêtre de 15 minutes). */
final class TestLoginThrottle
{
    public static function make(\PDO $pdo): LoginThrottle
    {
        $events = new MysqlThrottleEventRepository($pdo);

        return new LoginThrottle(
            new SubjectThrottle($events, 'login', 20, '-15 minutes'),
            new SubjectThrottle($events, 'login-id', 5, '-15 minutes'),
        );
    }
}
