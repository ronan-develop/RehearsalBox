<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Account\Entity\User;
use App\Assembly\Entity\AssemblyStatus;
use App\Assembly\Exception\AssemblyLockedException;
use App\Assembly\Exception\AssemblyNotFoundException;
use App\Assembly\Exception\AssemblyValidationException;
use App\Assembly\Repository\AssemblyContentRepositoryInterface;
use App\Assembly\Repository\AssemblyRepositoryInterface;
use App\Database\TransactionRunner;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Saisie, validation et correction des comptes rendus d'AG (#361). Chaque opération vérifie le droit administrateur, puis verrouille la
 * ligne (FOR UPDATE) et contrôle statut et révision dans UNE transaction : un compte rendu validé n'est jamais écrit. Les noms sont
 * relus en base par AssemblyContentResolver (le client n'envoie que des identifiants) et recopiés.
 * Le journal ne reçoit que des identifiants, jamais un nom ni un texte.
 */
final class AssemblyService
{
    public function __construct(
        private readonly AdminAccess $access,
        private readonly AssemblyInputPolicy $policy,
        private readonly AssemblyCompleteness $completeness,
        private readonly AssemblyRepositoryInterface $assemblies,
        private readonly AssemblyContentRepositoryInterface $contents,
        private readonly AssemblyContentResolver $resolver,
        private readonly TransactionRunner $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input `kind` et `heldOn`
     * @throws AssemblyValidationException
     */
    public function create(User $actor, array $input, ?\DateTimeImmutable $now = null): int
    {
        $this->access->assertAdmin($actor);
        [$kind, $heldOn] = $this->policy->parseCreation($input);
        $id = $this->assemblies->insert($kind, $heldOn, $actor->id(), $now ?? new \DateTimeImmutable());
        $this->logger->info('Assemblée : compte rendu créé', ['actor' => $actor->id(), 'assembly' => $id]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input corps de la requête, validé par AssemblyInputPolicy
     * @return int la nouvelle révision
     * @throws AssemblyValidationException
     * @throws AssemblyLockedException     déjà validé, ou modifié entre-temps
     * @throws AssemblyNotFoundException
     */
    public function saveDraft(User $actor, int $id, int $expectedRevision, array $input, ?\DateTimeImmutable $now = null): int
    {
        $this->access->assertAdmin($actor);
        $draft = $this->policy->parse($input);
        $now ??= new \DateTimeImmutable();

        $revision = $this->transactions->run(function () use ($id, $expectedRevision, $draft, $now): int {
            $this->lockDraft($id, $expectedRevision);
            $content = $this->resolver->resolve($draft, $this->contents->load($id));
            if (!$this->assemblies->updateDraftHeader($id, $expectedRevision, $draft, $now)) {
                throw new AssemblyLockedException();
            }
            $this->contents->replace($id, $content);

            return $expectedRevision + 1;
        });
        $this->logger->info('Assemblée : brouillon enregistré', ['actor' => $actor->id(), 'assembly' => $id]);

        return $revision;
    }

    /**
     * @throws AssemblyValidationException compte rendu incomplet (champs manquants)
     * @throws AssemblyLockedException
     * @throws AssemblyNotFoundException
     */
    public function validate(User $actor, int $id, int $expectedRevision, ?\DateTimeImmutable $now = null): void
    {
        $this->access->assertAdmin($actor);
        $now ??= new \DateTimeImmutable();

        $this->transactions->run(function () use ($actor, $id, $expectedRevision, $now): void {
            $this->lockDraft($id, $expectedRevision);
            $assembly = $this->assemblies->find($id) ?? throw new AssemblyNotFoundException();
            $missing = $this->completeness->missing($assembly, $this->contents->load($id));
            if ($missing !== []) {
                throw new AssemblyValidationException($missing);
            }
            if (!$this->assemblies->markValidated($id, $expectedRevision, $actor->id(), $now)) {
                throw new AssemblyLockedException();
            }
        });
        $this->logger->info('Assemblée : compte rendu validé', ['actor' => $actor->id(), 'assembly' => $id]);
    }

    /**
     * Ouvre une nouvelle version en brouillon à partir de la dernière version validée.
     *
     * @return int l'identifiant du brouillon de correction
     * @throws AssemblyValidationException motif vide ou trop long
     * @throws AssemblyLockedException     version non validée ou déjà corrigée
     * @throws AssemblyNotFoundException
     */
    public function correct(User $actor, int $id, string $reason, ?\DateTimeImmutable $now = null): int
    {
        $this->access->assertAdmin($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new AssemblyValidationException(['reason' => 'Indiquez le motif de la correction (255 caractères au plus).']);
        }
        $now ??= new \DateTimeImmutable();

        $newId = $this->transactions->run(function () use ($actor, $id, $reason, $now): int {
            $lock = $this->assemblies->lock($id) ?? throw new AssemblyNotFoundException();
            if ($lock->status !== AssemblyStatus::Valide || $lock->hasSuccessor) {
                throw new AssemblyLockedException();
            }
            $newId = $this->assemblies->insertCorrection($id, $reason, $actor->id(), $now);
            $this->contents->copy($id, $newId);

            return $newId;
        });
        $this->logger->info('Assemblée : correction ouverte', ['actor' => $actor->id(), 'assembly' => $id, 'correction' => $newId]);

        return $newId;
    }

    /**
     * @throws AssemblyLockedException compte rendu validé : jamais supprimé
     * @throws AssemblyNotFoundException
     */
    public function deleteDraft(User $actor, int $id): void
    {
        $this->access->assertAdmin($actor);

        $this->transactions->run(function () use ($id): void {
            $lock = $this->assemblies->lock($id) ?? throw new AssemblyNotFoundException();
            if ($lock->status !== AssemblyStatus::Brouillon || !$this->assemblies->deleteDraft($id)) {
                throw new AssemblyLockedException();
            }
        });
        $this->logger->info('Assemblée : brouillon supprimé', ['actor' => $actor->id(), 'assembly' => $id]);
    }

    private function lockDraft(int $id, int $expectedRevision): void
    {
        $lock = $this->assemblies->lock($id) ?? throw new AssemblyNotFoundException();
        if ($lock->status !== AssemblyStatus::Brouillon || $lock->revision !== $expectedRevision) {
            throw new AssemblyLockedException();
        }
    }
}
