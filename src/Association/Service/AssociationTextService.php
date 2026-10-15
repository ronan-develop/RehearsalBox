<?php

declare(strict_types=1);

namespace App\Association\Service;

use App\Account\Entity\User;
use App\Association\Entity\AssociationText;
use App\Association\Exception\AssociationTextChangedException;
use App\Association\Exception\AssociationTextNotFoundException;
use App\Association\Exception\AssociationTextValidationException;
use App\Association\Repository\AssociationTextRepositoryInterface;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Petit CRUD des textes de l'association (statuts…), réservé aux administrateurs actifs (#472). Chaque opération vérifie le droit
 * dans le service, puis écrit en UNE requête atomique : la modification porte la révision lue par la page (`WHERE revision = ?`),
 * donc deux administrateurs ne s'écrasent pas en silence. Le journal ne reçoit que des identifiants, jamais un titre ni un texte.
 */
final class AssociationTextService
{
    public function __construct(
        private readonly AdminAccess $access,
        private readonly AssociationTextInputPolicy $policy,
        private readonly AssociationTextRepositoryInterface $texts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<AssociationText> */
    public function list(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return $this->texts->listAll();
    }

    /** @throws AssociationTextNotFoundException */
    public function get(User $actor, int $id): AssociationText
    {
        $this->access->assertAdmin($actor);

        return $this->texts->find($id) ?? throw new AssociationTextNotFoundException();
    }

    /**
     * @param array<string, mixed> $input `title` et `body`
     * @throws AssociationTextValidationException saisie refusée ou titre déjà pris
     */
    public function create(User $actor, array $input, ?\DateTimeImmutable $now = null): int
    {
        $this->access->assertAdmin($actor);
        [$title, $body] = $this->policy->parse($input);
        $id = $this->texts->insert($title, $body, $actor->id(), $now ?? new \DateTimeImmutable());
        $this->logger->info('Association : texte créé', ['actor' => $actor->id(), 'text' => $id]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input `title` et `body`
     * @return int la nouvelle révision
     * @throws AssociationTextValidationException saisie refusée ou titre déjà pris
     * @throws AssociationTextNotFoundException
     * @throws AssociationTextChangedException    modifié par un autre administrateur entre-temps
     */
    public function update(User $actor, int $id, int $expectedRevision, array $input, ?\DateTimeImmutable $now = null): int
    {
        $this->access->assertAdmin($actor);
        [$title, $body] = $this->policy->parse($input);

        if (!$this->texts->update($id, $expectedRevision, $title, $body, $actor->id(), $now ?? new \DateTimeImmutable())) {
            // L'écriture est refusée : le texte n'existe plus, ou sa révision a changé.
            throw $this->texts->find($id) === null ? new AssociationTextNotFoundException() : new AssociationTextChangedException();
        }
        $this->logger->info('Association : texte modifié', ['actor' => $actor->id(), 'text' => $id]);

        return $expectedRevision + 1;
    }

    /** @throws AssociationTextNotFoundException */
    public function delete(User $actor, int $id): void
    {
        $this->access->assertAdmin($actor);
        if (!$this->texts->delete($id)) {
            throw new AssociationTextNotFoundException();
        }
        $this->logger->info('Association : texte supprimé', ['actor' => $actor->id(), 'text' => $id]);
    }
}
