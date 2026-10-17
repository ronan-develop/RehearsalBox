<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Group\Entity\Group;

/** Une ligne de la page admin des utilisateurs : le compte, ses groupes (avec son rôle dans chacun), son état de verrouillage et sa dernière demande de lien de mot de passe (#502) et sa dernière connexion (#512). */
final class AdminUserItem
{
    /**
     * @param list<Group>           $groups
     * @param array<int, string>    $groupRoles identifiant de groupe => 'membre' | 'gestionnaire'
     */
    public function __construct(
        private readonly User $user,
        private readonly array $groups,
        private readonly bool $isLocked,
        private readonly array $groupRoles = [],
        private readonly ?MailActivity $mailActivity = null,
        private readonly ?\DateTimeImmutable $lastLoginAt = null,
    ) {
    }

    public function user(): User
    {
        return $this->user;
    }

    /** @return list<Group> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** Rôle de la personne dans ce groupe ('membre' | 'gestionnaire'), null si elle n'en est pas membre. */
    public function groupRole(int $groupId): ?string
    {
        return $this->groupRoles[$groupId] ?? null;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    /** Dernière connexion réussie, null si le compte ne s'est jamais connecté. */
    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /** Dernière demande de lien de mot de passe du compte, null si elle n'en a jamais fait. */
    public function mailActivity(): ?MailActivity
    {
        return $this->mailActivity;
    }
}
