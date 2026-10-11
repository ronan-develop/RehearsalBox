<?php

declare(strict_types=1);

namespace App\Account\Service\Announcement;

/**
 * Mémoire de l'annonce (#444) : un identifiant de compte par ligne, ajouté dès que son e-mail est parti. Un fichier plutôt qu'une
 * table : l'annonce est un envoi unique, une migration n'aurait pas de second usage. Aucune adresse n'y est écrite.
 */
final class AnnouncementLedger
{
    /** @var array<int, true>|null */
    private ?array $sent = null;

    public function __construct(private readonly string $path)
    {
    }

    public function has(int $userId): bool
    {
        return isset($this->load()[$userId]);
    }

    public function add(int $userId): void
    {
        // Écrit tout de suite : un envoi interrompu à mi-parcours ne renvoie pas aux comptes déjà servis.
        file_put_contents($this->path, $userId . "\n", FILE_APPEND | LOCK_EX);
        $this->sent = $this->load() + [$userId => true];
    }

    /** @return array<int, true> */
    private function load(): array
    {
        if ($this->sent !== null) {
            return $this->sent;
        }
        $this->sent = [];
        $lines = is_file($this->path) ? file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        foreach ($lines ?: [] as $line) {
            if (ctype_digit($line)) {
                $this->sent[(int) $line] = true;
            }
        }

        return $this->sent;
    }
}
