<?php

declare(strict_types=1);

namespace App\Tests\Account\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Service\Throttle\LoginThrottle;
use App\Account\Security\PasswordPolicy;
use App\Account\Service\UserAdminService;
use App\Account\Service\UserProvisioningService;
use App\Group\Repository\MysqlGroupRepository;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Support\TestLoginThrottle;
use PHPUnit\Framework\Attributes\Test;

/** #236 : « Débloquer » (administrateur) lève AUSSI le blocage annoncé à l'écran de connexion, tenu par identifiant. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class UserAdminUnlockThrottleTest extends RepositoryTestCase
{
    private LoginThrottle $throttle;
    private UserAdminService $service;
    private MysqlUserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->throttle = TestLoginThrottle::make($this->pdo);
        $this->service = new UserAdminService(
            $this->users,
            new MysqlGroupRepository($this->pdo),
            new UserProvisioningService($this->users, new FastPasswordHasher(), new PasswordPolicy()),
            $this->throttle,
        );
    }

    #[Test]
    public function testUnlockingAnAccountAlsoLiftsTheLoginBlockOfItsAddress(): void
    {
        $user = $this->users->save(new User(0, 'Alice@Rehearsalbox.test', 'hash', 'Alice', UserRole::Musicien, true, 0, null));
        $now = new \DateTimeImmutable();
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordFailure("203.0.113.{$i}", 'alice@rehearsalbox.test', $now);
        }
        self::assertGreaterThan(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $now));

        $this->service->unlock($user->id());

        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $now));
        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', 'ALICE@rehearsalbox.test', $now), 'casse sans importance');
    }

    #[Test]
    public function testUnlockingOneAccountLeavesTheBlockOfAnotherIdentifierAlone(): void
    {
        $alice = $this->users->save(new User(0, 'alice@rehearsalbox.test', 'hash', 'Alice', UserRole::Musicien, true, 0, null));
        $now = new \DateTimeImmutable();
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordFailure("203.0.113.{$i}", 'bob@rehearsalbox.test', $now);
        }

        $this->service->unlock($alice->id());

        self::assertGreaterThan(0, $this->throttle->remainingSeconds('198.51.100.9', 'bob@rehearsalbox.test', $now));
    }
}
