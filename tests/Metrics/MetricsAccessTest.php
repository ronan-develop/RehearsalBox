<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Metrics\MetricsAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetricsAccessTest extends TestCase
{
    private function user(string $email, UserRole $role = UserRole::Admin, bool $active = true): User
    {
        return new User(1, $email, 'hash', 'Test', $role, $active, 0, null);
    }

    #[Test]
    public function testOnlyTheConfiguredAccountIsAllowedWhateverTheCase(): void
    {
        $access = new MetricsAccess('Owner@rehearsalbox.test');

        self::assertTrue($access->allows($this->user('owner@rehearsalbox.test')));
        self::assertFalse($access->allows($this->user('other-admin@rehearsalbox.test')), 'un autre administrateur n\'a pas accès');
    }

    #[Test]
    public function testNobodyIsAllowedWithoutAConfiguredAddressNorWhenAnonymous(): void
    {
        self::assertFalse((new MetricsAccess(''))->allows($this->user('')), 'une adresse vide ne doit jamais correspondre');
        self::assertFalse((new MetricsAccess(''))->allows($this->user('owner@rehearsalbox.test')));
        self::assertFalse((new MetricsAccess('owner@rehearsalbox.test'))->allows(null));
    }

    #[Test]
    public function testTheAccountMustAlsoBeAnActiveAdministrator(): void
    {
        $access = new MetricsAccess('owner@rehearsalbox.test');

        self::assertFalse($access->allows($this->user('owner@rehearsalbox.test', UserRole::Musicien)));
        self::assertFalse($access->allows($this->user('owner@rehearsalbox.test', UserRole::Admin, false)));
    }
}
