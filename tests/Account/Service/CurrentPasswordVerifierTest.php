<?php

declare(strict_types=1);

namespace App\Tests\Account\Service;

use App\Account\Entity\UserRole;
use App\Account\Entity\User;
use App\Account\Repository\MysqlUserRepository;
use App\Tests\Support\FastPasswordHasher;
use App\Account\Service\CurrentPasswordVerifier;
use App\Account\Exception\UserValidationException;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class CurrentPasswordVerifierTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private CurrentPasswordVerifier $verifier;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->verifier = new CurrentPasswordVerifier($this->users, new FastPasswordHasher());
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
    }

    private function user(): User
    {
        return $this->users->save(new User(0, 'alice@rehearsalbox.test', (new FastPasswordHasher())->hash('le-bon-mot-de-passe'), 'Alice', UserRole::Musicien, true, 0, null));
    }

    #[Test]
    public function testTheRightPasswordPasses(): void
    {
        $alice = $this->user();

        $this->verifier->assertMatches($alice, 'le-bon-mot-de-passe', $this->now);

        $this->addToAssertionCount(1);
        self::assertSame(0, $this->users->findById($alice->id())->failedLoginAttempts());
    }

    #[Test]
    public function testAWrongPasswordIsRefusedOnTheCurrentPasswordFieldAndCountsAsAFailedLoginAttempt(): void
    {
        $alice = $this->user();

        try {
            $this->verifier->assertMatches($alice, 'faux', $this->now);
            self::fail('UserValidationException attendue.');
        } catch (UserValidationException $e) {
            self::assertSame(['currentPassword' => 'Mot de passe actuel incorrect.'], $e->fields());
        }

        self::assertSame(1, $this->users->findById($alice->id())->failedLoginAttempts());
    }

    #[Test]
    public function testRepeatedFailuresLockTheAccountLikeTheLoginDoes(): void
    {
        $alice = $this->user();

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->verifier->assertMatches($this->users->findById($alice->id()), 'faux', $this->now);
            } catch (UserValidationException) {
            }
        }

        self::assertTrue($this->users->findById($alice->id())->isLocked($this->now));
    }

    #[Test]
    public function testALockedAccountIsRefusedEvenWithTheRightPasswordAndWithoutCountingAnotherFailure(): void
    {
        $alice = $this->user();
        $locked = $this->users->save($alice->withLockedUntil($this->now->modify('+1 day')));

        try {
            $this->verifier->assertMatches($locked, 'le-bon-mot-de-passe', $this->now);
            self::fail('UserValidationException attendue.');
        } catch (UserValidationException $e) {
            self::assertArrayHasKey('currentPassword', $e->fields());
            self::assertStringContainsString('verrouillé', $e->fields()['currentPassword']);
        }

        self::assertSame(0, $this->users->findById($alice->id())->failedLoginAttempts());
    }
}
