<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Messaging\Crypto\MessageCipher;
use App\Messaging\Crypto\SodiumMessageCipher;

/** Chiffreur des tests de la messagerie (#171) : vraie clé fixe, mode strict : un test qui lirait du clair en base échoue. */
final class TestMessageCipher
{
    private static ?string $keyFile = null;

    private static function key(): string
    {
        return hash('sha256', 'cle-de-test-de-la-messagerie', true);
    }

    public static function make(): MessageCipher
    {
        return new SodiumMessageCipher(['k1' => self::key()], 'k1');
    }

    /**
     * Fichier de clés (0600, mode strict) contenant la MÊME clé que make() : pour les tests qui construisent le vrai conteneur
     * (`$config['messages']['key_file']`) et lisent ce que des dépôts de test ont écrit. Supprimé en fin de processus.
     */
    public static function keyFile(): string
    {
        if (self::$keyFile === null) {
            $path = sys_get_temp_dir() . '/rb-test-message-keys-' . bin2hex(random_bytes(4)) . '.json';
            $previous = umask(0o177);
            file_put_contents($path, json_encode(['current' => 'k1', 'allow_plaintext' => false, 'keys' => ['k1' => base64_encode(self::key())]]));
            umask($previous);
            register_shutdown_function(static fn () => @unlink($path));
            self::$keyFile = $path;
        }

        return self::$keyFile;
    }
}
