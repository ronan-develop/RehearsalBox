<?php

declare(strict_types=1);

namespace App\Group\Service;

use App\Group\Service\GroupFilesPurgerInterface;

/** Null Object : aucun fichier à retirer (scripts, tests). La production câble GroupDocumentService. */
final class NoGroupFilesPurger implements GroupFilesPurgerInterface
{
    public function filesOf(int $groupId): array
    {
        return [];
    }

    public function remove(array $paths): void
    {
    }
}
