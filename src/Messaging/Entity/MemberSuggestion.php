<?php

declare(strict_types=1);

namespace App\Messaging\Entity;

/** Membre proposé après « @ » : identifiant, nom affiché et groupes. Jamais d'adresse e-mail. */
final class MemberSuggestion
{
    /**
     * @param list<int>    $groupIds
     * @param list<string> $groupNames par ordre alphabétique
     */
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly array $groupIds,
        private readonly array $groupNames,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<int> */
    public function groupIds(): array
    {
        return $this->groupIds;
    }

    /** @return list<string> */
    public function groupNames(): array
    {
        return $this->groupNames;
    }
}
