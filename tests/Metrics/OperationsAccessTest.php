<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Metrics\OperationsAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** L'exploitation et les mesures se LISENT pour tout administrateur actif (décision du propriétaire, 2026-10-17) ; personne d'autre. */
final class OperationsAccessTest extends TestCase
{
    private function user(UserRole $role, bool $active = true): User
    {
        return new User(1, 'quelquun@rehearsalbox.test', 'hash', 'Test', $role, $active, 0, null);
    }

    #[Test]
    public function testAnyActiveAdministratorIsAllowed(): void
    {
        self::assertTrue((new OperationsAccess())->allows($this->user(UserRole::Admin)));
    }

    #[Test]
    public function testAMusicianADisabledAdministratorAndAVisitorAreRefused(): void
    {
        $access = new OperationsAccess();

        self::assertFalse($access->allows($this->user(UserRole::Musicien)));
        self::assertFalse($access->allows($this->user(UserRole::Admin, false)));
        self::assertFalse($access->allows(null));
    }
}
