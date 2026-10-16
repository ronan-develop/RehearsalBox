<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\RecurringTemplate;
use App\Accounting\Service\Recurring\TemplateDraft;

/** Accès à `accounting_recurring_templates` : des modèles, jamais des écritures ; ni suppression (on désactive). */
interface RecurringTemplateRepositoryInterface
{
    public function insert(TemplateDraft $draft, int $actorId, \DateTimeImmutable $now): int;

    /** Faux si le modèle n'existe pas. */
    public function update(int $id, TemplateDraft $draft): bool;

    public function find(int $id): ?RecurringTemplate;

    /** @return list<RecurringTemplate> les plus récents d'abord, au plus `$limit` */
    public function listAll(int $limit): array;
}
