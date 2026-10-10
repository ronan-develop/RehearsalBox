<?php

declare(strict_types=1);

namespace App\Messaging\Service\Direct;

use App\Account\Entity\User;
use App\Account\Repository\UserRepositoryInterface;
use App\Messaging\Entity\MemberSuggestion;
use App\Messaging\Repository\Mention\MemberDirectoryInterface;
use App\Messaging\Service\ConversationAccess;
use App\Security\Exception\AccessDeniedException;

/**
 * Annuaire de « Nouveau message » (#269) : tous les membres actifs avec qui l'on peut écrire, par nom et avec leurs groupes,
 * jamais d'adresse. Valide aussi le destinataire d'une page de démarrage (même règle et même refus que DirectMessagePolicy).
 */
final class DirectMemberListService
{
    /** Garde-fou d'affichage : la liste est rendue d'un bloc, filtrée dans le navigateur. */
    public const MAX_MEMBERS = 500;

    private readonly DirectMessagePolicy $policy;

    public function __construct(
        private readonly MemberDirectoryInterface $directory,
        private readonly UserRepositoryInterface $users,
    ) {
        $this->policy = new DirectMessagePolicy($users);
    }

    /** @return list<MemberSuggestion> les autres membres actifs, par ordre alphabétique */
    public function members(int $userId): array
    {
        return $this->directory->search('', $userId, self::MAX_MEMBERS);
    }

    /** @throws AccessDeniedException soi-même, personne inconnue ou inactive */
    public function recipient(int $userId, int $targetId): User
    {
        $this->policy->assertCanWrite($userId, $targetId);

        return $this->users->findById($targetId) ?? throw new AccessDeniedException(ConversationAccess::DENIED);
    }
}
