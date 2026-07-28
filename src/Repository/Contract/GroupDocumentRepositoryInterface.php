<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\GroupDocument;

interface GroupDocumentRepositoryInterface
{
    public function findById(int $id): ?GroupDocument;

    /** @return list<GroupDocument> */
    public function findByGroup(int $groupId): array;

    public function countByGroup(int $groupId): int;

    public function save(GroupDocument $document): GroupDocument;

    public function delete(int $id): void;
}
