<?php

declare(strict_types=1);

namespace App\Service\Exception;

/** Un groupe ou son profil refusé : nom, genre, couleur, e-mail de contact, composition ou concerts (erreur par champ). */
final class GroupValidationException extends FieldValidationException
{
}
