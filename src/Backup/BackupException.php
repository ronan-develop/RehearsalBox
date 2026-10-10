<?php

declare(strict_types=1);

namespace App\Backup;

/** Une sauvegarde a échoué ou n'est pas fiable : le message ne contient jamais un identifiant, un mot de passe ni du contenu de la base. */
final class BackupException extends \RuntimeException
{
}
