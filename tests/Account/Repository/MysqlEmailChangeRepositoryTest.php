<?php

declare(strict_types=1);

namespace App\Tests\Account\Repository;

use App\Account\Entity\UserRole;
use App\Account\Entity\User;
use App\Account\Repository\MysqlEmailChangeRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlEmailChangeRepositoryTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
    }

    private function insertUser(string $email): User
    {
        return (new MysqlUserRepository($this->pdo))->save(new User(0, $email, 'hash', 'Utilisateur', UserRole::Musicien, true, 0, null));
    }

    private function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }

    #[Test]
    public function testConsumeReturnsTheUserAndTheNewAddressOfAValidToken(): void
    {
        $repository = new MysqlEmailChangeRepository($this->pdo);
        $user = $this->insertUser('alice@rehearsalbox.test');
        $repository->create($user->id(), 'nouvelle@rehearsalbox.test', $this->hash('a'), $this->now->modify('+1 hour'), $this->now);

        $result = $repository->consume($this->hash('a'), $this->now->modify('+10 minutes'));

        self::assertSame(['userId' => $user->id(), 'newEmail' => 'nouvelle@rehearsalbox.test'], $result);
    }

    #[Test]
    public function testATokenIsSingleUse(): void
    {
        $repository = new MysqlEmailChangeRepository($this->pdo);
        $user = $this->insertUser('alice@rehearsalbox.test');
        $repository->create($user->id(), 'n@rehearsalbox.test', $this->hash('a'), $this->now->modify('+1 hour'), $this->now);

        self::assertNotNull($repository->consume($this->hash('a'), $this->now));
        self::assertNull($repository->consume($this->hash('a'), $this->now));
    }

    #[Test]
    public function testAnExpiredOrUnknownTokenIsRefused(): void
    {
        $repository = new MysqlEmailChangeRepository($this->pdo);
        $user = $this->insertUser('alice@rehearsalbox.test');
        $repository->create($user->id(), 'n@rehearsalbox.test', $this->hash('a'), $this->now->modify('+1 hour'), $this->now);

        self::assertNull($repository->consume($this->hash('a'), $this->now->modify('+61 minutes')), 'expiré');
        self::assertNull($repository->consume($this->hash('inconnu'), $this->now), 'inconnu');
    }

    #[Test]
    public function testInvalidateAllForUserCancelsPendingRequestsOfThatUserOnly(): void
    {
        $repository = new MysqlEmailChangeRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $repository->create($alice->id(), 'a2@rehearsalbox.test', $this->hash('a'), $this->now->modify('+1 hour'), $this->now);
        $repository->create($bob->id(), 'b2@rehearsalbox.test', $this->hash('b'), $this->now->modify('+1 hour'), $this->now);

        $repository->invalidateAllForUser($alice->id(), $this->now);

        self::assertNull($repository->consume($this->hash('a'), $this->now));
        self::assertNotNull($repository->consume($this->hash('b'), $this->now), 'la demande de Bob est intacte');
    }

    #[Test]
    public function testCountCreatedSinceCountsOnlyRecentRequestsOfTheUser(): void
    {
        $repository = new MysqlEmailChangeRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $repository->create($alice->id(), 'a1@rehearsalbox.test', $this->hash('1'), $this->now->modify('+1 hour'), $this->now->modify('-2 hours'));
        $repository->create($alice->id(), 'a2@rehearsalbox.test', $this->hash('2'), $this->now->modify('+1 hour'), $this->now->modify('-10 minutes'));
        $repository->create($alice->id(), 'a3@rehearsalbox.test', $this->hash('3'), $this->now->modify('+1 hour'), $this->now);
        $repository->create($bob->id(), 'b1@rehearsalbox.test', $this->hash('4'), $this->now->modify('+1 hour'), $this->now);

        self::assertSame(2, $repository->countCreatedSince($alice->id(), $this->now->modify('-1 hour')));
    }

    #[Test]
    public function testRequestsDisappearWithTheirUser(): void
    {
        $repository = new MysqlEmailChangeRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $repository->create($alice->id(), 'a2@rehearsalbox.test', $this->hash('a'), $this->now->modify('+1 hour'), $this->now);

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $alice->id());

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM email_changes')->fetchColumn());
    }
}
