<?php

declare(strict_types=1);

namespace App\Accounting\Exception;

use App\Validation\FieldValidationException;

/** Saisie comptable refusée (erreur par champ). */
final class AccountingValidationException extends FieldValidationException
{
}
