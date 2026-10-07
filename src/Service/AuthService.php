<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Group\Repository\GroupRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Security\PasswordHasherInterface;
use App\Security\SessionInterface;
use App\Service\Contract\AuthServiceInterface;

final class AuthService implements AuthServiceInterface
{
    private const SESSION_KEY_USER_ID = 'user_id';
    private const SESSION_KEY_ACTIVE_GROUP_ID = 'active_group_id';
    private const SESSION_KEY_SESSION_VERSION = 'session_version';
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCK_DURATION = '+15 minutes';

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

        // Compte inconnu, désactivé ou verrouillé : refus indiscernable d'un mauvais mot de passe, sans compter d'échec ni
        // ouvrir de session. Une opération de hachage est dépensée dans tous les cas : le temps de réponse ne révèle rien.
        if ($user === null || !$user->isActive() || $user->isLocked($now)) {
            $this->passwordHasher->simulateVerification($plainPassword);

            return null;
        }

        if (!$this->passwordHasher->verify($plainPassword, $user->passwordHash())) {
            // Écriture atomique ciblée (pas de « lire, calculer, réécrire la ligne » : des tentatives simultanées s'écraseraient).
            $this->userRepository->recordFailedLogin($user->id(), self::MAX_FAILED_ATTEMPTS, $now, self::LOCK_DURATION);

            return null;
        }

        $this->userRepository->resetFailedLogins($user->id());

        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY_USER_ID, $user->id());
        $this->session->set(self::SESSION_KEY_SESSION_VERSION, $user->sessionVersion());

        $groups = $this->groupRepository->findByMember($user->id());
        if (count($groups) === 1) {
            $this->session->set(self::SESSION_KEY_ACTIVE_GROUP_ID, $groups[0]->id());
        }

        return $user;
    }

    public function currentUser(): ?User
    {
        $userId = $this->session->get(self::SESSION_KEY_USER_ID);
        if (!is_int($userId)) {
            return null;
        }

        $user = $this->userRepository->findById($userId);
        if ($user === null || !$user->isActive()) {
            return null;
        }

        // Une session sans version (ouverte avant l'introduction du compteur) vaut 0.
        // Si le compteur de l'utilisateur a avancé (mot de passe changé, compte sécurisé),
        // cette session est périmée : traitée comme déconnectée.
        if ((int) $this->session->get(self::SESSION_KEY_SESSION_VERSION, 0) !== $user->sessionVersion()) {
            return null;
        }

        return $user;
    }

    public function refreshSession(User $user): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY_USER_ID, $user->id());
        $this->session->set(self::SESSION_KEY_SESSION_VERSION, $user->sessionVersion());
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
