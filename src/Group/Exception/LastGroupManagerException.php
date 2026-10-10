<?php

declare(strict_types=1);

namespace App\Group\Exception;

/** Un groupe ne reste jamais sans gestionnaire (#272) : le message est affichable (422). */
final class LastGroupManagerException extends \LogicException
{
}
