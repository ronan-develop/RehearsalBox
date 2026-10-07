<?php

declare(strict_types=1);

namespace App\Group\Repository;

use App\Group\Entity\GroupDocument;

interface GroupDocumentRepositoryInterface
{
    public function findById(int $id): ?GroupDocument;

    /** @return list<GroupDocument> */
    public function findByGroup(int $groupId): array;

    public function countByGroup(int $groupId): int;

    /**
     * Verrouille le groupe jusqu'à la fin de la transaction en cours (#222) : les envois d'un MÊME groupe passent l'un après
     * l'autre, ce qui rend atomique « compter puis ajouter » (le quota ne peut plus être dépassé par des envois simultanés).
     * À appeler dans une transaction ; les autres groupes ne sont jamais gênés.
     */
    public function lockGroupQuota(int $groupId): void;

    public function save(GroupDocument $document): GroupDocument;

    public function delete(int $id): void;
}
