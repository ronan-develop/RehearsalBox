<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Security\PasswordHasherInterface;
use App\Security\SessionInterface;
use App\Service\Contract\AuthServiceInterface;

final class AuthService implements AuthServiceInterface
{
    private const SESSION_KEY_USER_ID = 'user_id';
    private const SESSION_KEY_ACTIVE_GROUP_ID = 'active_group_id';
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_DURATION = '+15 minutes';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly SessionInterface $session,
        private readonly GroupRepositoryInterface $groupRepository,
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

        $groups = $this->groupRepository->findByMember($user->id());
        if (count($groups) === 1) {
            $this->session->set(self::SESSION_KEY_ACTIVE_GROUP_ID, $groups[0]->id());
        }

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

    public function groupsRequiringSelection(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            return [];
        }

        $groups = $this->groupRepository->findByMember($user->id());

        return count($groups) > 1 ? $groups : [];
    }

    public function selectActiveGroup(int $groupId): void
    {
        $user = $this->currentUser();

        if ($user === null || !$this->groupRepository->isMember($groupId, $user->id())) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        $this->session->set(self::SESSION_KEY_ACTIVE_GROUP_ID, $groupId);
    }
}
