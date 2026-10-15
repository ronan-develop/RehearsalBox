<?php

declare(strict_types=1);

namespace App\Association\Exception;

use App\Validation\FieldValidationException;

/** Titre ou texte refusé (erreur par champ : `title`, `body`). */
final class AssociationTextValidationException extends FieldValidationException
{
}
