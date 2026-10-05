<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\ConversationGuestRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\MemberDirectoryInterface;
use App\Security\Exception\AccessDeniedException;

/**
 * Liste proposée après « @ » (#178) : tous les membres actifs du site, retrouvés par leur nom, avec leur groupe pour les
 * distinguer ; jamais d'adresse. La recherche suppose d'être soi-même dans le contexte : participant de la conversation,
 * ou membre du groupe émetteur d'une nouvelle conversation. Au moins 2 caractères, au plus MAX_RESULTS résultats.
 */
final class MemberSearchService
{
    public const MIN_QUERY_LENGTH = 2;
    public const MAX_RESULTS = 10;

    public function __construct(
        private readonly MemberDirectoryInterface $directory,
        private readonly ConversationAccess $access,
        private readonly GroupRepositoryInterface $groups,
        private readonly ConversationGuestRepositoryInterface $guests,
    ) {
    }

    /**
     * @return list<array{id: int, name: string, groups: string, participant: bool}>
     *
     * @throws AccessDeniedException pas participant de la conversation
     */
    public function search(int $userId, string $query, int $conversationId): array
    {
        $conversation = $this->access->participant($userId, $conversationId);

        return $this->find($userId, $query, [$conversation->initiatorGroupId(), $conversation->targetGroupId()], $conversationId);
    }

    /**
     * Page de démarrage : la conversation n'existe pas encore, ses futurs groupes sont l'émetteur et le groupe visé.
     *
     * @return list<array{id: int, name: string, groups: string, participant: bool}>
     *
     * @throws AccessDeniedException pas membre du groupe émetteur, groupe visé inconnu
     */
    public function searchForNewConversation(int $userId, string $query, int $senderGroupId, int $targetGroupId): array
    {
        if ($senderGroupId === $targetGroupId
            || !$this->groups->isMember($senderGroupId, $userId)
            || $this->groups->findById($targetGroupId) === null) {
            throw new AccessDeniedException(ConversationAccess::DENIED);
        }

        return $this->find($userId, $query, [$senderGroupId, $targetGroupId], null);
    }

    /**
     * @param list<int> $groupIds
     *
     * @return list<array{id: int, name: string, groups: string, participant: bool}>
     */
    private function find(int $userId, string $query, array $groupIds, ?int $conversationId): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $choices = [];
        foreach ($this->directory->search($query, $userId, self::MAX_RESULTS) as $member) {
            $choices[] = [
                'id' => $member->id(),
                'name' => $member->name(),
                'groups' => implode(', ', $member->groupNames()),
                'participant' => array_intersect($member->groupIds(), $groupIds) !== []
                    || ($conversationId !== null && $this->guests->isGuest($conversationId, $member->id())),
            ];
        }

        return $choices;
    }
}
