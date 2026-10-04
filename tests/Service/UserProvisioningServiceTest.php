<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Repository\MysqlUserRepository;
use App\Security\NativePasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\Exception\UserValidationException;
use App\Service\UserProvisioningService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class UserProvisioningServiceTest extends RepositoryTestCase
{
    private function service(): UserProvisioningService
    {
        return new UserProvisioningService(new MysqlUserRepository($this->pdo), new NativePasswordHasher(), new PasswordPolicy());
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

    #[Test]
    public function testCreateWithTooShortPasswordReportsPasswordField(): void
    {
        try {
            $this->service()->create('denis@example.test', 'denis', UserRole::Admin, '1234567');
            self::fail('Une UserValidationException était attendue.');
        } catch (UserValidationException $e) {
            self::assertSame(['password' => 'Le mot de passe doit faire au moins 8 caractères.'], $e->fields());
        }

        self::assertNull((new MysqlUserRepository($this->pdo))->findByEmail('denis@example.test'));
    }

    #[Test]
    public function testCreateReportsEveryInvalidFieldAtOnce(): void
    {
        try {
            $this->service()->create('pas-un-email', '', UserRole::Musicien, 'court');
            self::fail('Une UserValidationException était attendue.');
        } catch (UserValidationException $e) {
            self::assertSame(['email', 'password', 'displayName'], array_keys($e->fields()));
        }
    }

    #[Test]
    public function testCreateWithExistingEmailReportsEmailField(): void
    {
        $this->service()->create('denis@example.test', 'denis', UserRole::Admin, 'first-pass');

        try {
            $this->service()->create('denis@example.test', 'autre', UserRole::Musicien, 'second-pass');
            self::fail('Une UserValidationException était attendue.');
        } catch (UserValidationException $e) {
            self::assertSame(['email' => 'Un compte existe déjà avec cet email.'], $e->fields());
        }
    }
}
