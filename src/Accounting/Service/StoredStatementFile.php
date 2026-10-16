<?php

declare(strict_types=1);

namespace App\Accounting\Service;

/** Fichier de relevé rangé dans le stockage : nom aléatoire, type vérifié sur la copie stockée, empreinte et taille de CETTE copie. */
final class StoredStatementFile
{
    public function __construct(
        public readonly string $storedName,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {
    }
}
