<?php

declare(strict_types=1);

namespace App\Accounting\Import\Parse;

/** Ce que contient un fichier : les lignes lues et, une par une, les lignes refusées (numéro d'enregistrement et raison, sans contenu). */
final class ParseResult
{
    /**
     * @param list<ParsedLine> $lines
     * @param array<int, string> $errors raison du refus par numéro d'enregistrement
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $errors,
    ) {
    }
}
