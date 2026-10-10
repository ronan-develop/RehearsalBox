<?php

declare(strict_types=1);

namespace App\Messaging\Crypto;

/** Un texte n'a pas pu être lu : le message d'erreur ne contient jamais le texte, la valeur stockée ni une clé. */
final class MessageCipherException extends \RuntimeException
{
}
