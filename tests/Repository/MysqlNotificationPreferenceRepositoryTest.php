<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlNotificationPreferenceRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlNotificationPreferenceRepositoryTest extends RepositoryTestCase
{
    private MysqlNotificationPreferenceRepository $preferences;
    private MysqlUserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preferences = new MysqlNotificationPreferenceRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
    }

    #[Test]
    public function testEmailNotificationsAreEnabledByDefault(): void
    {
        self::assertTrue($this->preferences->emailEnabled($this->user('alice')->id()));
    }

    #[Test]
    public function testUnsubscribingAffectsOnlyThatPersonAndCanBeUndone(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');

        $this->preferences->setEmailEnabled($alice->id(), false);

        self::assertFalse($this->preferences->emailEnabled($alice->id()));
        self::assertTrue($this->preferences->emailEnabled($bob->id()));

        $this->preferences->setEmailEnabled($alice->id(), true);
        self::assertTrue($this->preferences->emailEnabled($alice->id()));
    }

    #[Test]
    public function testAnUnknownPersonNeverReceivesAnything(): void
    {
        self::assertFalse($this->preferences->emailEnabled(999999));
    }

    #[Test]
    public function testSavingTheUserDoesNotResetThePreference(): void
    {
        $alice = $this->user('alice');
        $this->preferences->setEmailEnabled($alice->id(), false);

        $this->users->save($alice->withDisplayName('Alice Martin'));

        self::assertFalse($this->preferences->emailEnabled($alice->id()), 'une modification du profil ne réactive pas les e-mails');
    }
}
