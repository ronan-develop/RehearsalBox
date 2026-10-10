<?php

declare(strict_types=1);

namespace App\Messaging\Crypto;

/**
 * libsodium `secretbox` (XSalsa20-Poly1305) : un nonce aléatoire par valeur, authentifié (une valeur altérée est refusée).
 * Format stocké `v1.<idClé>:<base64url(nonce + chiffré)>` : la version du format et l'identifiant de la clé permettent de
 * faire tourner la clé sans casser l'existant (les anciennes clés restent dans le trousseau pour lire, la clé courante écrit).
 * `$allowPlaintext` n'est vrai que pendant la transition (messages d'avant le chiffrement) ; ensuite le clair est refusé.
 */
final class SodiumMessageCipher implements MessageCipher
{
    private const VERSION = 'v1';
    private const FORMAT = '/^v1\.([a-z0-9]{1,16}):([A-Za-z0-9_-]+)$/';
    private const KEY_ID = '/^[a-z0-9]{1,16}$/';
    private const VARIANT = SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;

    /**
     * @param array<string, string> $keys         identifiant de clé => clé brute de 32 octets
     * @param string                $currentKeyId clé qui chiffre ; les autres ne servent qu'à lire
     *
     * @throws \InvalidArgumentException trousseau invalide
     */
    public function __construct(
        private readonly array $keys,
        private readonly string $currentKeyId,
        private readonly bool $allowPlaintext = false,
    ) {
        if ($keys === [] || !isset($keys[$currentKeyId])) {
            throw new \InvalidArgumentException('Trousseau de clés vide ou clé courante absente.');
        }
        foreach ($keys as $id => $key) {
            if (preg_match(self::KEY_ID, (string) $id) !== 1 || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \InvalidArgumentException('Clé invalide : identifiant [a-z0-9]{1,16} et 32 octets attendus.');
            }
        }
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = sodium_crypto_secretbox($plain, $nonce, $this->keys[$this->currentKeyId]);

        return self::VERSION . '.' . $this->currentKeyId . ':' . sodium_bin2base64($nonce . $sealed, self::VARIANT);
    }

    public function decrypt(string $stored): string
    {
        $parsed = $this->parse($stored);
        if ($parsed === null) {
            if (!$this->allowPlaintext) {
                throw new MessageCipherException('Valeur non chiffrée refusée.');
            }

            return $stored;
        }

        [$keyId, $raw] = $parsed;
        if (!isset($this->keys[$keyId])) {
            throw new MessageCipherException('Clé de chiffrement inconnue.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->keys[$keyId],
        );
        if ($plain === false) {
            throw new MessageCipherException('Valeur altérée ou clé incorrecte.');
        }

        return $plain;
    }

    public function isEncrypted(string $stored): bool
    {
        return $this->parse($stored) !== null;
    }

    public function isCurrent(string $stored): bool
    {
        return ($this->parse($stored)[0] ?? null) === $this->currentKeyId;
    }

    /**
     * @return array{string, string}|null identifiant de clé et octets (nonce + chiffré) ; null si la valeur n'a pas la forme
     *                                    d'une valeur chiffrée (donc du clair d'avant le chiffrement)
     */
    private function parse(string $stored): ?array
    {
        if (preg_match(self::FORMAT, $stored, $parts) !== 1) {
            return null;
        }
        try {
            $raw = sodium_base642bin($parts[2], self::VARIANT);
        } catch (\SodiumException) {
            return null;
        }

        return strlen($raw) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ? [$parts[1], $raw] : null;
    }
}
