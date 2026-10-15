<?php

declare(strict_types=1);

namespace App\Association\Exception;

use App\Validation\FieldValidationException;

/** Compte ou nom refusé pour un poste du bureau (erreur par champ : `userId`, `fullName`). */
final class AssociationBoardValidationException extends FieldValidationException
{
}
