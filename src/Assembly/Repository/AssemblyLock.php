<?php

declare(strict_types=1);

namespace App\Assembly\Repository;

use App\Assembly\Entity\AssemblyStatus;

/** État d'une ligne lue FOR UPDATE avant une décision (validation, correction, enregistrement). */
final class AssemblyLock
{
    public function __construct(
        public readonly AssemblyStatus $status,
        public readonly int $revision,
        public readonly int $version,
        public readonly bool $hasSuccessor,
    ) {
    }
}
