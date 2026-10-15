<?php

declare(strict_types=1);

namespace App\Assembly\Repository;

use App\Assembly\Entity\Assembly;
use App\Assembly\Entity\AssemblyKind;
use App\Assembly\Service\AssemblyDraft;

/** Accès à la table `assemblies` (une ligne par version). Les écritures de brouillon sont atomiques : statut et révision dans le WHERE. */
interface AssemblyRepositoryInterface
{
    public function insert(AssemblyKind $kind, \DateTimeImmutable $heldOn, int $createdBy, \DateTimeImmutable $now): int;

    public function find(int $id): ?Assembly;

    /** Lecture FOR UPDATE, dans une transaction. */
    public function lock(int $id): ?AssemblyLock;

    /** Faux si le compte rendu n'est plus un brouillon ou si la révision a changé. */
    public function updateDraftHeader(int $id, int $expectedRevision, AssemblyDraft $draft, \DateTimeImmutable $now): bool;

    /** Faux si le compte rendu n'est plus un brouillon ou si la révision a changé. */
    public function markValidated(int $id, int $expectedRevision, int $actorId, \DateTimeImmutable $now): bool;

    /** Nouvelle version en brouillon, en-tête copié ; AssemblyLockedException si la version a déjà un successeur. */
    public function insertCorrection(int $sourceId, string $reason, int $actorId, \DateTimeImmutable $now): int;

    /** Faux si le compte rendu n'est plus un brouillon. */
    public function deleteDraft(int $id): bool;

    /** @return list<Assembly> versions sans successeur, les plus récentes d'abord */
    public function listHeads(): array;

    public function successorOf(int $id): ?Assembly;
}
