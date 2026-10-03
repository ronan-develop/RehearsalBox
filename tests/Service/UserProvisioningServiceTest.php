<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Repository\MysqlUserRepository;
use App\Security\NativePasswordHasher;
use App\Service\UserProvisioningService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class UserProvisioningServiceTest extends RepositoryTestCase
{
    private function service(): UserProvisioningService
    {
        return new UserProvisioningService(new MysqlUserRepository($this->pdo), new NativePasswordHasher());
    }

    #[Test]
    public function testCreatePersistsActiveUserWithHashedPasswordAndRole(): void
    {
        $user = $this->service()->create('denis@example.test', 'denis', UserRole::Admin, 'secret-pass');

        $found = (new MysqlUserRepository($this->pdo))->findByEmail('denis@example.test');
        self::assertNotNull($found);
        self::assertSame($user->id(), $found->id());
        self::assertSame('denis', $found->displayName());
        self::assertSame(UserRole::Admin, $found->role());
        self::assertTrue($found->isActive());
        self::assertNotSame('secret-pass', $found->passwordHash());
        self::assertTrue((new NativePasswordHasher())->verify('secret-pass', $found->passwordHash()));
    }

    #[Test]
    public function testCreateWithExistingEmailThrowsAndKeepsOriginalUser(): void
    {
        $this->service()->create('denis@example.test', 'denis', UserRole::Admin, 'first-pass');

        try {
            $this->service()->create('denis@example.test', 'autre', UserRole::Musicien, 'second-pass');
            self::fail('Une InvalidArgumentException était attendue.');
        } catch (\InvalidArgumentException) {
        }

        $found = (new MysqlUserRepository($this->pdo))->findByEmail('denis@example.test');
        self::assertSame('denis', $found->displayName());
        self::assertTrue((new NativePasswordHasher())->verify('first-pass', $found->passwordHash()));
    }

    #[Test]
    public function testCreateWithInvalidEmailThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create('pas-un-email', 'denis', UserRole::Admin, 'secret-pass');
    }

    #[Test]
    public function testCreateWithEmptyPasswordOrNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create('denis@example.test', 'denis', UserRole::Admin, '');
    }

    #[Test]
    public function testCreateWithEmptyDisplayNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create('denis@example.test', '', UserRole::Admin, 'secret-pass');
    }
}
