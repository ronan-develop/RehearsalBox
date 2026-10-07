<?php

declare(strict_types=1);

namespace App\Validation;

/** Refus de saisie avec un message par champ (les contrôleurs en font un 422). */
abstract class FieldValidationException extends \InvalidArgumentException
{
    /** @param array<string, string> $fields message d'erreur par champ */
    public function __construct(private readonly array $fields)
    {
        parent::__construct(implode(' ', $fields));
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
