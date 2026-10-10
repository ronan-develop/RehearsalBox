<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /** @return list<User> tous les comptes, par nom affiché */
    public function findAll(): array;

    /**
     * Identifiants des administrateurs actifs, VERROUILLÉS (`FOR UPDATE`, dans l'ordre des identifiants) jusqu'à la fin de la
     * transaction en cours : deux administrateurs qui retirent chacun un accès en même temps se mettent en file, le second voit
     * le résultat du premier et ne peut donc pas supprimer le dernier administrateur. À appeler dans une transaction.
     *
     * @return list<int>
     */
    public function lockActiveAdminIds(): array;

    /** Change le rôle d'un compte, sans toucher au reste (ni à la version de session : le rôle est relu à chaque requête). */
    public function updateRole(int $userId, UserRole $role): void;

    public function save(User $user): User;

    /**
     * Compte un échec de connexion de façon ATOMIQUE (une seule requête : des tentatives simultanées ne s'écrasent pas)
     * et verrouille le compte dès que `$maxAttempts` échecs sont atteints.
     */
    public function recordFailedLogin(int $userId, int $maxAttempts, \DateTimeImmutable $now, string $lockDuration): void;

    /** Remet à zéro le compteur d'échecs et le verrou (connexion réussie, déblocage par un administrateur). */
    public function resetFailedLogins(int $userId): void;
}
