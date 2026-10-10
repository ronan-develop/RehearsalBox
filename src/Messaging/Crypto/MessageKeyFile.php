<?php

declare(strict_types=1);

namespace App\Messaging\Crypto;

/**
 * Fichier de clés du chiffrement des messages (#171) : JSON sur le serveur, HORS dépôt, hors base et hors racine web, lisible par
 * le seul propriétaire (0600), créé UNE fois par `bin/message-keys.php init` et jamais régénéré par un déploiement (le script
 * de déploiement le relie à chaque release, comme config.local.php). Sans fichier valide, aucun texte n'est lu ni écrit : on
 * ne retombe jamais sur du clair en silence. Les messages restent lisibles d'une release à l'autre tant que ce fichier l'est.
 * Contenu : `current` (clé qui chiffre), `allow_plaintext` (transition), `keys` (identifiant => clé de 32 octets en base64).
 */
final class MessageKeyFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @throws MessageCipherException fichier absent, illisible, invalide ou lisible par d'autres que son propriétaire */
    public function cipher(): SodiumMessageCipher
    {
        $data = $this->read();
        $keys = [];
        foreach ($data['keys'] as $id => $encoded) {
            $key = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($key === false) {
                throw new MessageCipherException('Fichier de clés invalide : clé illisible.');
            }
            $keys[(string) $id] = $key;
        }

        try {
            return new SodiumMessageCipher($keys, $data['current'], $data['allow_plaintext']);
        } catch (\InvalidArgumentException) {
            throw new MessageCipherException('Fichier de clés invalide : trousseau incohérent.');
        }
    }

    /** @throws \LogicException le fichier existe déjà (jamais écrasé : ses clés déchiffrent les messages) */
    public function init(): void
    {
        $this->assertAbsent();
        $this->write(['current' => 'k1', 'allow_plaintext' => true, 'keys' => ['k1' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))]]);
    }

    /** Fin de la transition : le texte non chiffré est désormais refusé. Toutes les clés sont conservées. */
    public function endTransition(): void
    {
        $this->cipher(); // valide le fichier avant de le réécrire
        $data = $this->read();
        $data['allow_plaintext'] = false;
        $this->write($data);
    }

    /**
     * Rouvre la transition : le texte en clair d'avant le chiffrement redevient lisible. À utiliser après la restauration d'un dump
     * d'AVANT le chiffrement (ses messages sont en clair et le mode strict les refuserait) ; on rechiffre ensuite, puis on referme.
     */
    public function beginTransition(): void
    {
        $this->cipher(); // valide le fichier avant de le réécrire
        $data = $this->read();
        $data['allow_plaintext'] = true;
        $this->write($data);
    }

    /** Ajoute une clé et la rend courante ; les anciennes restent pour lire. @return string l'identifiant de la nouvelle clé */
    public function rotate(): string
    {
        $this->cipher();
        $data = $this->read();
        $id = 'k' . (count($data['keys']) + 1);
        $data['keys'][$id] = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $data['current'] = $id;
        $this->write($data);

        return $id;
    }

    /** @return array{current: string, keys: list<string>, allowPlaintext: bool} noms des clés seulement, jamais leur valeur */
    public function status(): array
    {
        $data = $this->read();

        return ['current' => $data['current'], 'keys' => array_map('strval', array_keys($data['keys'])), 'allowPlaintext' => $data['allow_plaintext']];
    }

    /** Contenu complet du fichier, À CONSERVER hors du serveur (KeePass) : sans lui, les messages sont perdus. */
    public function export(): string
    {
        $this->cipher();

        return (string) file_get_contents($this->path);
    }

    /**
     * Restaure une sauvegarde sur un serveur sans fichier de clés.
     *
     * @throws \LogicException           le fichier existe déjà
     * @throws \InvalidArgumentException sauvegarde illisible ou invalide
     */
    public function import(string $backup): void
    {
        $this->assertAbsent();
        try {
            $this->write($this->validated($backup));
        } catch (MessageCipherException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        $this->cipher();
    }

    /** @return array{current: string, allow_plaintext: bool, keys: array<string, mixed>} */
    private function read(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new MessageCipherException('Fichier de clés absent ou illisible.');
        }
        if ((fileperms($this->path) & 0o077) !== 0) {
            throw new MessageCipherException('Fichier de clés lisible par d\'autres que son propriétaire : chmod 600 attendu.');
        }

        return $this->validated((string) file_get_contents($this->path));
    }

    /** @return array{current: string, allow_plaintext: bool, keys: array<string, mixed>} */
    private function validated(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['current'] ?? null) || !is_bool($data['allow_plaintext'] ?? null) || !is_array($data['keys'] ?? null) || $data['keys'] === []) {
            throw new MessageCipherException('Fichier de clés invalide.');
        }

        return ['current' => $data['current'], 'allow_plaintext' => $data['allow_plaintext'], 'keys' => $data['keys']];
    }

    private function assertAbsent(): void
    {
        if (file_exists($this->path)) {
            throw new \LogicException('Le fichier de clés existe déjà : il n\'est jamais écrasé.');
        }
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $target = $this->writeTarget();
        $previous = umask(0o177);
        try {
            $temporary = $target . '.tmp' . bin2hex(random_bytes(4));
            file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            chmod($temporary, 0o600);
            rename($temporary, $target); // remplacement atomique : jamais de fichier à moitié écrit
        } finally {
            umask($previous);
        }
    }

    /**
     * Où écrire : en production, le chemin de la release est un LIEN vers shared/ (le fichier survit aux déploiements). Remplacer
     * le lien par un fichier ordinaire enterrerait les clés dans la release, perdues au déploiement suivant : on écrit dans la
     * cible du lien, même pendante (premier passage), et le lien reste un lien.
     */
    private function writeTarget(): string
    {
        $path = $this->path;
        for ($hops = 0; is_link($path) && $hops < 5; ++$hops) {
            $next = (string) readlink($path);
            $path = str_starts_with($next, '/') ? $next : dirname($path) . '/' . $next;
        }

        return $path;
    }
}
