<?php

declare(strict_types=1);

namespace App\Operations\Backup;

use App\Operations\BoundedFile;
use Psr\Clock\ClockInterface;

/**
 * Ce que le propriétaire a fait HORS du serveur et que le serveur ne peut pas voir (#375) : la dernière copie des dumps sur son poste
 * (`offsite`) et la dernière sauvegarde de la clé dans KeePass (`key-saved`). Écrit uniquement en ligne de commande (bin/ops-declare.php),
 * jamais depuis le web, avec l'horloge du serveur ; la page ne fait que lire. Fichier JSON en 0600, écrit atomiquement, dates ISO seules.
 */
final class Declarations
{
    public const OFFSITE = 'offsite';
    public const KEY_SAVED = 'key-saved';

    private const KEYS = [self::OFFSITE => 'offsiteAt', self::KEY_SAVED => 'keySavedAt'];

    public function __construct(private readonly ?string $file, private readonly ClockInterface $clock)
    {
    }

    public function offsiteAt(): ?\DateTimeImmutable
    {
        return $this->dateOf(self::OFFSITE);
    }

    public function keySavedAt(): ?\DateTimeImmutable
    {
        return $this->dateOf(self::KEY_SAVED);
    }

    /** @throws \InvalidArgumentException déclaration inconnue ; @throws \RuntimeException écriture impossible */
    public function declare(string $what): void
    {
        if (!isset(self::KEYS[$what])) {
            throw new \InvalidArgumentException('Déclaration inconnue.');
        }
        if ($this->file === null) {
            throw new \RuntimeException('Dossier d\'exploitation non configuré.');
        }

        $data = $this->read();
        $data[self::KEYS[$what]] = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $temporary = $this->file . '.tmp';
        $previous = umask(0o177);
        try {
            if (@file_put_contents($temporary, (string) json_encode($data), LOCK_EX) === false || !@rename($temporary, $this->file)) {
                @unlink($temporary);
                throw new \RuntimeException('Déclaration impossible à écrire.');
            }
        } finally {
            umask($previous);
        }
    }

    private function dateOf(string $what): ?\DateTimeImmutable
    {
        $value = $this->read()[self::KEYS[$what]] ?? null;
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));

        return $date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value ? null : $date;
    }

    /** @return array<string, string> seules les deux clés connues, valeurs textuelles */
    private function read(): array
    {
        $json = BoundedFile::tail($this->file, 4096);
        $data = $json === null ? null : json_decode($json, true, 3);
        $clean = [];
        foreach (self::KEYS as $key) {
            if (is_array($data) && is_string($data[$key] ?? null)) {
                $clean[$key] = $data[$key];
            }
        }

        return $clean;
    }
}
