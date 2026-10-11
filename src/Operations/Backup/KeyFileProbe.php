<?php

declare(strict_types=1);

namespace App\Operations\Backup;

use App\Messaging\Crypto\MessageCipherException;
use App\Messaging\Crypto\MessageKeyFile;

/**
 * Sonde du fichier de clés (#171) pour la page d'exploitation (#375) : dit s'il est présent, aux droits stricts et lisible, et
 * combien de clés il porte. Le lien symbolique posé par le déploiement (config/ vers shared/) est suivi, exactement comme le lit
 * l'application ; un lien cassé vaut « absent ». N'expose AUCUNE valeur ni aucun nom de clé : seul un état et un nombre sortent
 * d'ici. Les droits sont testés avant toute lecture du contenu.
 */
final class KeyFileProbe
{
    public function __construct(private readonly string $keyFile)
    {
    }

    /** @return array{state: KeyFileState, keys: int, transition: bool} */
    public function inspect(): array
    {
        $none = ['keys' => 0, 'transition' => false];
        if ($this->keyFile === '' || !is_file($this->keyFile)) {
            return ['state' => KeyFileState::Missing] + $none;
        }
        $permissions = @fileperms($this->keyFile);
        if ($permissions === false || !is_readable($this->keyFile)) {
            return ['state' => KeyFileState::Unreadable] + $none;
        }
        if (($permissions & 0o077) !== 0) {
            return ['state' => KeyFileState::TooOpen] + $none;
        }

        try {
            $status = (new MessageKeyFile($this->keyFile))->status();
        } catch (MessageCipherException) {
            return ['state' => KeyFileState::Unreadable] + $none;
        }

        return ['state' => KeyFileState::Ok, 'keys' => count($status['keys']), 'transition' => $status['allowPlaintext']];
    }
}
