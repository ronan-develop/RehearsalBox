<?php

declare(strict_types=1);

namespace App\Assembly\Exception;

use App\Validation\FieldValidationException;

/** Saisie ou validation d'un compte rendu refusée (erreur par champ, clés pointées : `attendees.2.name`, `board.tresorier`). */
final class AssemblyValidationException extends FieldValidationException
{
}
