<?php

declare(strict_types=1);

namespace App\Account\Exception;

/** Règle de gestion des comptes violée (ex. désactiver le dernier admin) : le message est affichable. */
final class UserAdminRuleException extends \RuntimeException
{
}
