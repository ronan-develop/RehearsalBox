<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\PasswordHasherInterface;
use App\Security\SessionInterface;
use App\Service\Contract\AuthServiceInterface;

final class AuthService implements AuthServiceInterface
{
    private const SESSION_KEY_USER_ID = 'user_id';
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_DURATION = '+15 minutes';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly SessionInterface $session,
    ) {
    }

    public function attempt(string $email, string $plainPassword): ?User
    {
        $user = $this->userRepository->findByEmail($email);
        $now = new \DateTimeImmutable();

        if ($user === null) {
            return null;
        }

        if ($user->isLocked($now)) {
            return null;
        }

        if (!$this->passwordHasher->verify($plainPassword, $user->passwordHash())) {
            $this->userRepository->save(
                $user->withFailedLoginAttempt(self::MAX_FAILED_ATTEMPTS, $now, self::LOCK_DURATION)
            );

            return null;
        }

        $user = $this->userRepository->save($user->withResetFailedAttempts());

        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY_USER_ID, $user->id());

        return $user;
    }

    public function currentUser(): ?User
    {
        $userId = $this->session->get(self::SESSION_KEY_USER_ID);

        return is_int($userId) ? $this->userRepository->findById($userId) : null;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
