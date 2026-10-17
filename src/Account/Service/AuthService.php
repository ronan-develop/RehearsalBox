<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Group\Repository\GroupRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Account\Security\PasswordHasherInterface;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Security\SessionPolicy;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use App\Account\Service\AuthServiceInterface;

final class AuthService implements AuthServiceInterface
{
    private const SESSION_KEY_USER_ID = 'user_id';
    private const SESSION_KEY_ACTIVE_GROUP_ID = 'active_group_id';
    private const SESSION_KEY_SESSION_VERSION = 'session_version';
    private const SESSION_KEY_STARTED_AT = 'session_started_at';
    private const SESSION_KEY_LAST_SEEN_AT = 'session_last_seen_at';
    /**
     * Échecs de mot de passe avant le verrou DU COMPTE (15 minutes). Seuil haut (#441) : l'acharnement d'une seule adresse est arrêté bien
     * avant (5 par couple adresse/identifiant, LoginThrottle) et ne peut donc pas verrouiller la personne visée ; le verrou ne
     * sert plus qu'à une attaque répartie sur de nombreuses adresses.
     */
    public const MAX_FAILED_ATTEMPTS = 20;
    public const LOCK_DURATION = '+15 minutes';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly SessionInterface $session,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly ClockInterface $clock = new Clock(),
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
        $this->userRepository->setLastLogin($user->id(), $this->clock->now());

        $this->session->regenerate();
        // Le jeton CSRF d'avant la connexion ne vaut plus (#426) : un nouveau est émis à la page suivante.
        $this->session->remove(CsrfTokenManager::SESSION_KEY);
        $this->session->set(self::SESSION_KEY_USER_ID, $user->id());
        $this->session->set(self::SESSION_KEY_SESSION_VERSION, $user->sessionVersion());
        $this->session->set(self::SESSION_KEY_STARTED_AT, $this->now());
        $this->session->set(self::SESSION_KEY_LAST_SEEN_AT, $this->now());

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

        if ($this->isTimedOut()) {
            $this->session->remove(self::SESSION_KEY_USER_ID);

            return null;
        }

        return $user;
    }

    /** Limites d'inactivité et absolue (#425). Une session ouverte avant leur introduction est datée d'aujourd'hui : pas de déconnexion générale. */
    private function isTimedOut(): bool
    {
        $now = $this->now();
        $startedAt = $this->session->get(self::SESSION_KEY_STARTED_AT);
        $lastSeenAt = $this->session->get(self::SESSION_KEY_LAST_SEEN_AT);
        if (!is_int($startedAt) || !is_int($lastSeenAt)) {
            $startedAt = $lastSeenAt = $now;
            $this->session->set(self::SESSION_KEY_STARTED_AT, $startedAt);
        }
        if (SessionPolicy::isExpired($startedAt, $lastSeenAt, $now)) {
            return true;
        }
        $this->session->set(self::SESSION_KEY_LAST_SEEN_AT, $now);

        return false;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    public function refreshSession(User $user): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY_USER_ID, $user->id());
        $this->session->set(self::SESSION_KEY_SESSION_VERSION, $user->sessionVersion());
        $this->session->set(self::SESSION_KEY_LAST_SEEN_AT, $this->now());
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
