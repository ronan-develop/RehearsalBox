<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Repository\MysqlUserRepository;
use App\Tests\Support\FastPasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\Exception\UserValidationException;
use App\Service\UserProvisioningService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class UserProvisioningServiceTest extends RepositoryTestCase
{
    private function service(): UserProvisioningService
    {
        return new UserProvisioningService(new MysqlUserRepository($this->pdo), new FastPasswordHasher(), new PasswordPolicy());
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
        self::assertTrue((new FastPasswordHasher())->verify('secret-pass', $found->passwordHash()));
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
        self::assertTrue((new FastPasswordHasher())->verify('first-pass', $found->passwordHash()));
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

    #[Test]
    public function testCreateWithoutPasswordPersistsAnActiveAccountNobodyCanLogInto(): void
    {
        $user = $this->service()->createWithoutPassword('younasse@example.test', 'Younasse', UserRole::Musicien);

        $found = (new MysqlUserRepository($this->pdo))->findByEmail('younasse@example.test');
        self::assertNotNull($found);
        self::assertSame($user->id(), $found->id());
        self::assertTrue($found->isActive());
        self::assertSame(UserRole::Musicien, $found->role());
        self::assertNotSame('', $found->passwordHash());
        // Le secret est aléatoire et inconnu : aucun mot de passe plausible ne le vérifie.
        $hasher = new FastPasswordHasher();
        foreach (['', 'younasse@example.test', 'Younasse', bin2hex(random_bytes(8))] as $guess) {
            self::assertFalse($hasher->verify($guess, $found->passwordHash()));
        }
    }

    #[Test]
    public function testCreateWithoutPasswordGivesEveryAccountADifferentSecret(): void
    {
        $first = $this->service()->createWithoutPassword('a@example.test', 'A', UserRole::Musicien);
        $second = $this->service()->createWithoutPassword('b@example.test', 'B', UserRole::Musicien);

        self::assertNotSame($first->passwordHash(), $second->passwordHash());
    }

    #[Test]
    public function testCreateWithoutPasswordValidatesEmailAndNameButNotAPassword(): void
    {
        try {
            $this->service()->createWithoutPassword('pas-un-email', '', UserRole::Musicien);
            self::fail('UserValidationException attendue.');
        } catch (UserValidationException $e) {
            self::assertArrayHasKey('email', $e->fields());
            self::assertArrayHasKey('displayName', $e->fields());
            self::assertArrayNotHasKey('password', $e->fields());
        }
    }

    #[Test]
    public function testCreateWithoutPasswordRejectsAnExistingEmail(): void
    {
        $this->service()->createWithoutPassword('a@example.test', 'A', UserRole::Musicien);

        $this->expectException(UserValidationException::class);

        $this->service()->createWithoutPassword('a@example.test', 'Autre', UserRole::Musicien);
    }

    #[Test]
    public function testCreateAppliesTheSameDisplayNameRulesAsTheProfilePage(): void
    {
        // Une seule politique (DisplayNamePolicy) : un nom qu'un utilisateur ne pourrait pas se donner
        // lui-même n'est pas non plus accepté à la création d'un compte.
        foreach (["Ali\nce", "Ali\x00ce", "Ali\u{202E}ce", str_repeat('é', 101)] as $name) {
            try {
                $this->service()->createWithoutPassword('x@example.test', $name, UserRole::Musicien);
                self::fail('UserValidationException attendue : ' . json_encode($name));
            } catch (UserValidationException $e) {
                self::assertArrayHasKey('displayName', $e->fields());
            }
        }

        $user = $this->service()->createWithoutPassword('ok@example.test', '  ' . str_repeat('é', 100) . '  ', UserRole::Musicien);
        self::assertSame(str_repeat('é', 100), $user->displayName(), '100 caractères accentués acceptés, espaces rognés');
    }
}
