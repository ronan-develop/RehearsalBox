<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Mention;

use App\Messaging\Entity\MemberSuggestion;

interface MemberDirectoryInterface
{
    /**
     * Membres actifs dont le nom contient $query (sous-chaîne, jokers SQL neutralisés), hors $exceptUserId.
     *
     * @return list<MemberSuggestion> par ordre alphabétique, au plus $limit
     */
    public function search(string $query, int $exceptUserId, int $limit): array;
}
