<?php

declare(strict_types=1);

namespace App\Accounting\Repository\Import;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Import\Rules\LabelRule;

/** Mémoire des libellés (#362) : règles explicites, visibles et modifiables. */
interface LabelRuleRepositoryInterface
{
    /** @return list<LabelRule> les plus récentes d'abord, au plus 500 */
    public function all(): array;

    /** Crée la règle, ou remplace la cible de celle qui a déjà ce motif et ce sens ; rend son identifiant. */
    public function remember(string $pattern, Direction $direction, EntryCategory $category, PaymentMethod $method, int $actorId, \DateTimeImmutable $now): int;

    public function find(int $id): ?LabelRule;

    /** Change la catégorie et le moyen de paiement d'une règle ; faux si elle n'existe pas. */
    public function retarget(int $id, EntryCategory $category, PaymentMethod $method): bool;

    public function delete(int $id): bool;
}
