<?php

declare(strict_types=1);

namespace App\Accounting\Service\Recurring;

use App\Account\Entity\User;
use App\Accounting\Entity\RecurringTemplate;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\RecurringTemplateNotFoundException;
use App\Accounting\Repository\RecurringTemplateRepositoryInterface;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Modèles d'écritures récurrentes (#362), réservés aux administrateurs actifs. Un modèle décrit ce qui se répète ; il ne crée aucune
 * écriture (voir RecurringOccurrenceService). Modifier un modèle ne touche ni les écritures ni les échéances déjà décidées. Pas de
 * suppression : on désactive. Le journal ne reçoit que des identifiants.
 */
final class RecurringTemplateService
{
    private const LIST_LIMIT = 200;

    public function __construct(
        private readonly AdminAccess $access,
        private readonly TemplateInputPolicy $policy,
        private readonly RecurringTemplateRepositoryInterface $templates,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input voir TemplateInputPolicy::parse
     * @throws AccountingValidationException
     */
    public function create(User $actor, array $input, ?\DateTimeImmutable $now = null): int
    {
        $this->access->assertAdmin($actor);
        $id = $this->templates->insert($this->policy->parse($input), $actor->id(), $now ?? new \DateTimeImmutable());
        $this->logger->info('Comptabilité : modèle récurrent créé', ['actor' => $actor->id(), 'template' => $id]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input voir TemplateInputPolicy::parse
     * @throws RecurringTemplateNotFoundException
     * @throws AccountingValidationException
     */
    public function update(User $actor, int $id, array $input): void
    {
        $this->access->assertAdmin($actor);
        $draft = $this->policy->parse($input);
        if (!$this->templates->update($id, $draft)) {
            throw new RecurringTemplateNotFoundException();
        }
        $this->logger->info('Comptabilité : modèle récurrent modifié', ['actor' => $actor->id(), 'template' => $id]);
    }

    /** @return list<RecurringTemplate> */
    public function list(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return $this->templates->listAll(self::LIST_LIMIT);
    }
}
