<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Identifiants de la base pour les programmes clients MariaDB (`mariadb-dump`, `mariadb`) : écrits dans un fichier d'options TEMPORAIRE
 * en 0600, jamais en argument (visibles dans la liste des processus), ni dans l'environnement, ni dans un message d'erreur (#167, #241).
 * Connexion en utf8mb4 : le serveur est en latin1 par défaut, accents et emojis des messages doivent survivre.
 */
final class ClientOptions
{
    /**
     * @param array{host: string, port: string, name: string, user: string, password: string} $db
     *
     * @throws \InvalidArgumentException configuration incomplète
     */
    public function __construct(private readonly array $db)
    {
        foreach (['host', 'port', 'name', 'user'] as $key) {
            if ($db[$key] === '') {
                throw new \InvalidArgumentException('Configuration de la base incomplète.');
            }
        }
    }

    public function databaseName(): string
    {
        return $this->db['name'];
    }

    /** Contenu du fichier d'options (identifiants échappés comme l'attend le client MariaDB). */
    public function contents(): string
    {
        return "[client]\n"
            . "host={$this->db['host']}\n"
            . "port={$this->db['port']}\n"
            . "user={$this->db['user']}\n"
            . 'password="' . addcslashes($this->db['password'], "\"\\") . "\"\n"
            . "default-character-set=utf8mb4\n";
    }

    /** @throws BackupException */
    public function writeTemporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rbdump');
        if ($path === false) {
            throw new BackupException('Fichier temporaire impossible.');
        }
        chmod($path, 0o600); // avant d'y écrire le mot de passe
        file_put_contents($path, $this->contents());

        return $path;
    }
}
