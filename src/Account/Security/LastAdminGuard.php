<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Repository\UserRepositoryInterface;

/**
 * Règle unique « on ne retire pas ses droits à l'administration » (#272), partagée par la désactivation et le changement de rôle :
 * personne ne retire son propre accès d'administrateur (il passerait sinon à côté de son mot de passe ou se bloquerait par erreur),
 * et jamais le DERNIER administrateur actif. La vérification se fait dans la transaction de l'appelant, sur les administrateurs
 * verrouillés (`lockActiveAdminIds`) : deux administrateurs simultanés ne peuvent pas la franchir tous les deux.
 */
final class LastAdminGuard
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * @param string $verb « désactiver » ou « rétrograder » : seulement pour le message affiché
     *
     * @throws UserAdminRuleException
     */
    public function assertMayLoseAdmin(User $target, int $actorUserId, string $verb): void
    {
        if ($target->id() === $actorUserId) {
            throw new UserAdminRuleException("Vous ne pouvez pas {$verb} votre propre compte.");
        }
        if ($target->isActive() && $target->hasRole(UserRole::Admin) && count($this->users->lockActiveAdminIds()) <= 1) {
            throw new UserAdminRuleException("Impossible de {$verb} le dernier administrateur actif.");
        }
    }
}
