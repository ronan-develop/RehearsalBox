<?php

declare(strict_types=1);

namespace App\Tests\Account\Service;

use App\Account\Entity\UserRole;
use App\Account\Entity\User;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Exception\UserNotFoundException;
use App\Account\Exception\UserValidationException;
use App\Account\Service\ProfileService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class ProfileServiceTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private ProfileService $service;
    private \App\Account\Repository\MysqlNotificationPreferenceRepository $preferences;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->preferences = new \App\Account\Repository\MysqlNotificationPreferenceRepository($this->pdo);
        $this->service = new ProfileService($this->users, $this->preferences);
    }

    private function user(string $email, string $name): User
    {
        return $this->users->save(new User(0, $email, 'hash', $name, UserRole::Musicien, true, 0, null));
    }

    #[Test]
    public function testUpdateDisplayNamePersistsTheNewNameAndNothingElse(): void
    {
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');

        $updated = $this->service->updateDisplayName($alice->id(), 'Alice Martin');

        self::assertSame('Alice Martin', $updated->displayName());
        $found = $this->users->findById($alice->id());
        self::assertSame('Alice Martin', $found->displayName());
        self::assertSame('alice@rehearsalbox.test', $found->email());
        self::assertSame($alice->passwordHash(), $found->passwordHash());
        self::assertSame($alice->sessionVersion(), $found->sessionVersion());
    }

    #[Test]
    public function testTheNameIsTrimmedAndAccentsAndSymbolsAreAllowed(): void
    {
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');

        self::assertSame("Zoé O'Brien-Dupont", $this->service->updateDisplayName($alice->id(), "  Zoé O'Brien-Dupont \t")->displayName());
    }

    #[Test]
    public function testAnEmptyOrBlankNameIsRefused(): void
    {
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');

        foreach (['', '   ', "\t\n"] as $name) {
            try {
                $this->service->updateDisplayName($alice->id(), $name);
                self::fail('UserValidationException attendue.');
            } catch (UserValidationException $e) {
                self::assertArrayHasKey('displayName', $e->fields());
            }
        }
        self::assertSame('Alice', $this->users->findById($alice->id())->displayName());
    }

    #[Test]
    public function testANameLongerThanOneHundredCharactersIsRefusedButOneHundredAccentedCharactersIsFine(): void
    {
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');

        $this->service->updateDisplayName($alice->id(), str_repeat('é', 100));
        $this->expectException(UserValidationException::class);

        $this->service->updateDisplayName($alice->id(), str_repeat('é', 101));
    }

    #[Test]
    public function testControlCharactersAreRefused(): void
    {
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');

        foreach (["Ali\x00ce", "Ali\nce", "Ali\x1bce", "Ali\u{202E}ce"] as $name) {
            try {
                $this->service->updateDisplayName($alice->id(), $name);
                self::fail('Un nom avec un caractère de contrôle doit être refusé : ' . json_encode($name));
            } catch (UserValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testUnknownUserThrowsNotFound(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->service->updateDisplayName(9999, 'Alice');
    }

    #[Test]
    public function testAPersonCanUnsubscribeFromAndResubscribeToMentionEmails(): void
    {
        $user = $this->users->save(new User(0, 'alice@rehearsalbox.test', 'hash', 'Alice', UserRole::Musicien, true, 0, null));

        $this->service->updateEmailNotifications($user->id(), false);
        self::assertFalse($this->preferences->emailEnabled($user->id()));

        $this->service->updateEmailNotifications($user->id(), true);
        self::assertTrue($this->preferences->emailEnabled($user->id()));
    }

    #[Test]
    public function testUpdatingThePreferenceOfAnUnknownPersonIsRefused(): void
    {
        $this->expectException(\App\Account\Exception\UserNotFoundException::class);

        $this->service->updateEmailNotifications(999999, false);
    }
}
