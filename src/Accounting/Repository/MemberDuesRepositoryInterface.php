<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\Member;

/** Adhérents (comptes actifs) et dispenses de cotisation. Les comptes ne sont jamais supprimés : une dispense reste valide. */
interface MemberDuesRepositoryInterface
{
    public function isActiveMember(int $userId): bool;

    /** @return list<Member> par nom, au plus `$limit` */
    public function listActive(int $limit): array;

    /** Pose (`$exempt` vrai) ou retire la dispense d'un adhérent pour un mois (premier jour) ; sans effet si l'état est déjà celui demandé. */
    public function setExemption(int $userId, \DateTimeImmutable $month, bool $exempt, int $actorId, \DateTimeImmutable $now): void;

    /**
     * @return array<string, true> clés `idAdhérent|AAAA-MM-01` des mois dispensés dans l'intervalle
     */
    public function exemptions(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
