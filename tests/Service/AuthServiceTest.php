<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlUserRepository;
use App\Security\CsrfTokenManager;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;

final class AuthServiceTest extends RepositoryTestCase
{
    private function makeService(): array
    {
        $userRepository = new MysqlUserRepository($this->pdo);
        $session = new InMemorySession();
        $service = new AuthService($userRepository, new NativePasswordHasher(), $session);

        return [$service, $userRepository, $session];
    }

    private function createUser(MysqlUserRepository $repository, string $email, string $password): User
    {
        return $repository->save(new User(
            id: 0,
            email: $email,
            passwordHash: (new NativePasswordHasher())->hash($password),
            displayName: 'Test',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    public function testAttemptWithValidCredentialsReturnsUserAndRegeneratesSession(): void
    {
        [$service, $userRepository, $session] = $this->makeService();
        $this->createUser($userRepository, 'alice@rehearsalbox.test', 'password123');

        $user = $service->attempt('alice@rehearsalbox.test', 'password123');

        self::assertNotNull($user);
        self::assertSame('alice@rehearsalbox.test', $user->email());
        self::assertTrue($session->regenerated);
    }

    public function testAttemptWithWrongPasswordReturnsNull(): void
    {
        [$service, $userRepository] = $this->makeService();
        $this->createUser($userRepository, 'bob@rehearsalbox.test', 'password123');

        $user = $service->attempt('bob@rehearsalbox.test', 'mauvais-mot-de-passe');

        self::assertNull($user);
    }

    public function testAttemptWithUnknownEmailReturnsNull(): void
    {
        [$service] = $this->makeService();

        $user = $service->attempt('inconnu@rehearsalbox.test', 'peu-importe');

        self::assertNull($user);
    }

    public function testAttemptLocksAccountAfterFiveFailedAttempts(): void
    {
        [$service, $userRepository] = $this->makeService();
        $created = $this->createUser($userRepository, 'chris@rehearsalbox.test', 'password123');

        for ($i = 0; $i < 5; $i++) {
            $service->attempt('chris@rehearsalbox.test', 'mauvais');
        }

        // Même avec le bon mot de passe, le compte verrouillé refuse la connexion.
        $result = $service->attempt('chris@rehearsalbox.test', 'password123');

        self::assertNull($result);
    }

    public function testAttemptResetsFailedAttemptsAfterSuccessfulLogin(): void
    {
        [$service, $userRepository] = $this->makeService();
        $this->createUser($userRepository, 'dana@rehearsalbox.test', 'password123');

        $service->attempt('dana@rehearsalbox.test', 'mauvais');
        $service->attempt('dana@rehearsalbox.test', 'mauvais');
        $service->attempt('dana@rehearsalbox.test', 'password123');

        $reloaded = $userRepository->findByEmail('dana@rehearsalbox.test');
        self::assertNotNull($reloaded);
    }

    public function testCurrentUserReturnsNullWhenNoUserInSession(): void
    {
        [$service] = $this->makeService();

        self::assertNull($service->currentUser());
    }

    public function testCurrentUserReturnsUserAfterSuccessfulAttempt(): void
    {
        [$service, $userRepository] = $this->makeService();
        $this->createUser($userRepository, 'eve@rehearsalbox.test', 'password123');
        $service->attempt('eve@rehearsalbox.test', 'password123');

        $current = $service->currentUser();

        self::assertNotNull($current);
        self::assertSame('eve@rehearsalbox.test', $current->email());
    }

    public function testLogoutDestroysSession(): void
    {
        [$service, , $session] = $this->makeService();

        $service->logout();

        self::assertTrue($session->destroyed);
    }
}
