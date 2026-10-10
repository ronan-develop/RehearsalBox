<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Exception\UserNotFoundException;
use App\Account\Exception\UserValidationException;
use App\Account\Repository\UserRepositoryInterface;
use App\Account\Security\DisplayNamePolicy;
use App\Account\Security\LastAdminGuard;
use App\Database\TransactionRunner;
use Psr\Log\LoggerInterface;

/**
 * Un administrateur corrige le nom et l'adresse d'un compte, et change son rôle (#272). Jamais de mot de passe : il n'en choisit ni n'en
 * connaît. Chaque modification laisse UNE ligne dans le journal (qui, quel compte, quels champs : identifiants seulement, jamais une
 * adresse ni un nom). L'adresse change comme un changement confirmé (sessions fermées, liens révoqués, alerte à l'ancienne adresse
 * APRÈS le commit) mais sans lien de confirmation ; l'administrateur ne change pas sa PROPRE adresse d'ici (Mon compte, avec son mot de passe).
 */
final class UserAccountAdminService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly EmailChangeService $emailChange,
        private readonly LastAdminGuard $lastAdminGuard,
        private readonly DisplayNamePolicy $displayNames,
        private readonly TransactionRunner $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws UserNotFoundException
     * @throws UserValidationException nom ou adresse refusés, adresse déjà utilisée (rien n'est écrit)
     * @throws UserAdminRuleException  changer sa propre adresse
     */
    public function updateIdentity(int $userId, string $displayName, string $email, int $actorUserId, ?\DateTimeImmutable $now = null): User
    {
        $now ??= new \DateTimeImmutable();
        $displayName = $this->displayNames->normalize($displayName);
        $violation = $this->displayNames->violation($displayName);
        if ($violation !== null) {
            throw new UserValidationException(['displayName' => $violation]);
        }
        $email = trim($email);

        [$saved, $oldEmail, $changed] = $this->transactions->run(function () use ($userId, $displayName, $email, $actorUserId, $now): array {
            $user = $this->users->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");
            $emailChanged = strcasecmp($email, $user->email()) !== 0;
            if ($emailChanged && $userId === $actorUserId) {
                throw new UserAdminRuleException('Modifiez votre propre adresse depuis Mon compte.');
            }

            $renamed = $user->withDisplayName($displayName);
            $saved = $emailChanged ? $this->emailChange->applyNewAddress($renamed, $email, $now) : $this->users->save($renamed);

            return [$saved, $user->email(), array_keys(array_filter(['displayName' => $user->displayName() !== $displayName, 'email' => $emailChanged]))];
        });

        if (in_array('email', $changed, true)) {
            $this->emailChange->alertPreviousAddress($oldEmail, $saved->email());
        }
        if ($changed !== []) {
            $this->logger->info('Administration : compte modifié', ['actor' => $actorUserId, 'user' => $userId, 'fields' => $changed]);
        }

        return $saved;
    }

    /**
     * @throws UserNotFoundException
     * @throws UserAdminRuleException se rétrograder soi-même, rétrograder le dernier administrateur actif
     */
    public function changeRole(int $userId, UserRole $role, int $actorUserId): User
    {
        [$saved, $changed] = $this->transactions->run(function () use ($userId, $role, $actorUserId): array {
            $user = $this->users->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");
            if ($user->hasRole($role)) {
                return [$user, false];
            }
            if ($user->hasRole(UserRole::Admin)) {
                $this->lastAdminGuard->assertMayLoseAdmin($user, $actorUserId, 'rétrograder');
            }
            $this->users->updateRole($userId, $role);

            return [$this->users->findById($userId) ?? $user, true];
        });

        if ($changed) {
            $this->logger->info('Administration : rôle modifié', ['actor' => $actorUserId, 'user' => $userId, 'role' => $role->value]);
        }

        return $saved;
    }
}
