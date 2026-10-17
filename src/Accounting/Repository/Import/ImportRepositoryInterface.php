<?php

declare(strict_types=1);

namespace App\Accounting\Repository\Import;

use App\Accounting\Import\ImportLine;
use App\Accounting\Import\Parse\CsvProfile;
use App\Accounting\Import\Parse\ParsedLine;

/** Salle d'attente de l'import CSV (#362) : lignes en attente, lots, empreintes anti-doublon et dernier profil de colonnes. Aucune écriture du livre ici. */
interface ImportRepositoryInterface
{
    public function profile(): ?CsvProfile;

    public function saveProfile(CsvProfile $profile, int $actorId, \DateTimeImmutable $now): void;

    /** Ouvre un lot ; null si ce fichier (même empreinte) a déjà été importé. */
    public function openBatch(string $fileSha256, int $actorId, \DateTimeImmutable $now): ?int;

    public function closeBatch(int $batchId, int $staged, int $duplicates, int $errors): void;

    /** Réserve l'empreinte d'une ligne : faux si cette ligne a déjà été vue (doublon). */
    public function claimHash(string $lineHash, \DateTimeImmutable $now): bool;

    public function addLine(int $batchId, ParsedLine $line, \DateTimeImmutable $now): int;

    /** @return list<ImportLine> lignes en attente, par date, au plus `$limit` */
    public function pending(int $limit): array;

    /** Ligne verrouillée pour la durée de la transaction en cours. */
    public function lockLine(int $id): ?ImportLine;

    /** Passe une ligne EN ATTENTE à validée ou écartée ; faux si elle ne l'était plus. */
    public function decide(int $id, string $status, ?int $entryId, \DateTimeImmutable $now): bool;

    /** Supprime les lignes créées avant `$before` (le contenu, jamais les empreintes) et les lots et empreintes plus vieux que `$keepHashesBefore` ; rend le nombre de lignes supprimées. */
    public function purge(\DateTimeImmutable $before, \DateTimeImmutable $keepHashesBefore): int;
}
