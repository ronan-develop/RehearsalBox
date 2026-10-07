<?php

declare(strict_types=1);

namespace App\Service\Exception;

use App\Validation\FieldValidationException;

/** Une demande de créneau modifiée avec une date ou un motif invalide (erreur par champ : `occurrenceDate`, `reason`). */
final class AvailabilityValidationException extends FieldValidationException
{
}
