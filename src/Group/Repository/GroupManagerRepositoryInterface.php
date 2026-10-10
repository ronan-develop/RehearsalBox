<?php

declare(strict_types=1);

namespace App\Group\Repository;

/** Rôle de gestionnaire d'un groupe (peut envoyer des documents et modifier le profil) : promotion, rétrogradation, verrou des gestionnaires. */
interface GroupManagerRepositoryInterface
{
    public function promoteToManager(int $groupId, int $userId): void;

    public function demoteToMember(int $groupId, int $userId): void;

    /**
     * Identifiants des gestionnaires du groupe, VERROUILLÉS (`FOR UPDATE`, dans l'ordre) jusqu'à la fin de la transaction en cours :
     * deux administrateurs qui retirent chacun un gestionnaire du même groupe se mettent en file (#272). À appeler dans une transaction.
     *
     * @return list<int>
     */
    public function lockManagerIds(int $groupId): array;
}
