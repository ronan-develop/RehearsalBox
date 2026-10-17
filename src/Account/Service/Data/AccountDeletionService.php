<?php

declare(strict_types=1);

namespace App\Account\Service\Data;

use App\Account\Entity\UserRole;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Exception\UserNotFoundException;
use App\Account\Repository\EmailChangeRepositoryInterface;
use App\Account\Repository\PasswordResetRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Account\Security\PasswordHasherInterface;
use App\Account\Service\CurrentPasswordVerifier;
use App\Database\TransactionRunner;
use App\Group\Exception\LastGroupManagerException;
use App\Group\Repository\GroupRepositoryInterface;
use App\Group\Service\GroupManagerService;
use App\Mail\Mailbox;
use Symfony\Component\Clock\ClockInterface;

/**
 * Suppression de SON compte (#431), mot de passe redemandé. La ligne de la personne RESTE, anonymisée (« Ancien membre », adresse
 * inutilisable, aucun mot de passe valable, compte inactif, sessions fermées) : les conversations, réservations et demandes des autres
 * y sont rattachées et doivent rester lisibles. Elle quitte ses groupes, ses liens en attente (mot de passe oublié, changement
 * d'adresse) sont révoqués. L'ancienne adresse est prévenue APRÈS la validation, sans jamais annuler la suppression si l'envoi échoue.
 * Refusé : un administrateur (un autre administrateur le rétrograde d'abord) et le dernier gestionnaire d'un groupe (il passe la main).
 */
final class AccountDeletionService
{
    public const FORMER_NAME = 'Ancien membre';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly GroupRepositoryInterface $groups,
        private readonly GroupManagerService $managers,
        private readonly CurrentPasswordVerifier $passwords,
        private readonly PasswordResetRepositoryInterface $resets,
        private readonly EmailChangeRepositoryInterface $emailChanges,
        private readonly PasswordHasherInterface $hasher,
        private readonly TransactionRunner $transactions,
        private readonly Mailbox $mailbox,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws UserNotFoundException
     * @throws \App\Account\Exception\UserValidationException mot de passe faux ou compte verrouillé
     * @throws UserAdminRuleException                         compte administrateur
     * @throws LastGroupManagerException                      dernier gestionnaire d'un groupe
     */
    public function delete(int $userId, string $password): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $user = $this->users->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");
        $this->passwords->assertMatches($user, $password, $now);

        $oldEmail = $user->email();
        $this->transactions->run(function () use ($userId, $now): void {
            $current = $this->users->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");
            if ($current->hasRole(UserRole::Admin)) {
                throw new UserAdminRuleException("Un compte administrateur ne se supprime pas lui-même : demandez à un autre administrateur de le rétrograder d'abord.");
            }

            foreach ($this->groups->findByMember($userId) as $group) {
                $this->managers->assertNotLastManager($group->id(), $userId);
                $this->groups->removeMember($group->id(), $userId);
            }
            $this->resets->invalidateAllForUser($userId, $now, PasswordResetRepositoryInterface::PURPOSE_RESET);
            $this->resets->invalidateAllForUser($userId, $now, PasswordResetRepositoryInterface::PURPOSE_ALERT);
            $this->emailChanges->invalidateAllForUser($userId, $now);

            $this->users->save(
                $current
                    ->withEmail(sprintf('ancien-membre-%d@supprime.invalid', $userId))
                    ->withDisplayName(self::FORMER_NAME)
                    ->withPasswordHash($this->hasher->hash(bin2hex(random_bytes(32))))
                    ->withActive(false),
            );
            $this->users->clearLastLogin($userId);
        });

        $this->mailbox->sendSafely(fn () => $this->mailbox->compose(
            $oldEmail,
            'votre compte a été supprimé',
            'account-deleted',
            ['preheader' => 'Votre compte RehearsalBox a été supprimé à votre demande.'],
        ), sprintf('Suppression de compte : confirmation par e-mail impossible (utilisateur #%d)', $userId));
    }
}
