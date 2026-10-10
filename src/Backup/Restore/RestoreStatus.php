<?php

declare(strict_types=1);

namespace App\Backup\Restore;

use Symfony\Component\Clock\ClockInterface;

/**
 * État d'une restauration (#241), gardé dans un FICHIER et non dans la base : la base est justement en cours de remplacement.
 * Le même objet sert au processus de restauration (qui écrit) et à la page d'administration (qui lit). Un verrou de fichier
 * (flock, libéré par le système si le processus meurt) garantit qu'une seule restauration tourne à la fois.
 * Dossier en 0700, fichiers en 0600 ; aucun identifiant ni contenu de la base n'y figure.
 */
final class RestoreStatus
{
    private const STATUS_FILE = 'restore-status.json';
    private const LOCK_FILE = 'restore.lock';

    /** @var resource|null */
    private $lockHandle;

    private string $file = '';
    private string $startedAt = '';
    private string $safetyBackup = '';

    public function __construct(private readonly string $directory, private readonly ClockInterface $clock)
    {
    }

    public function begin(string $file): void
    {
        $this->file = $file;
        $this->startedAt = $this->now();
        $this->safetyBackup = '';
        $this->write('running', 'start', '');
    }

    public function step(string $step): void
    {
        $this->write('running', $step, '');
    }

    /** @param string $notes suites à vérifier (migrations, clés, documents) : affichées avec le résultat */
    public function finish(string $safetyBackup, string $notes = ''): void
    {
        $this->safetyBackup = $safetyBackup;
        $this->write('done', 'done', $notes);
    }

    public function fail(string $message, string $safetyBackup = ''): void
    {
        $this->safetyBackup = $safetyBackup;
        $this->write('failed', 'failed', $message);
    }

    /** @return array{state: string, file: string, step: string, startedAt: string, updatedAt: string, message: string, safetyBackup: string}|null */
    public function read(): ?array
    {
        $json = @file_get_contents($this->directory . '/' . self::STATUS_FILE);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $read = [];
        foreach (['state', 'file', 'step', 'startedAt', 'updatedAt', 'message', 'safetyBackup'] as $key) {
            $read[$key] = is_string($data[$key] ?? null) ? $data[$key] : '';
        }

        return $read;
    }

    /** Vrai si une restauration tient le verrou en ce moment (même depuis un autre processus). */
    public function isRunning(): bool
    {
        if ($this->lockHandle !== null) {
            return true;
        }
        $handle = $this->openLock();
        if ($handle === null) {
            return false;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    public function acquire(): bool
    {
        $handle = $this->openLock();
        if ($handle === null || !flock($handle, LOCK_EX | LOCK_NB)) {
            $handle === null || fclose($handle);

            return false;
        }
        $this->lockHandle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /** @return resource|null */
    private function openLock()
    {
        $this->prepareDirectory();
        $handle = @fopen($this->directory . '/' . self::LOCK_FILE, 'c');

        return $handle === false ? null : $handle;
    }

    private function write(string $state, string $step, string $message): void
    {
        $this->prepareDirectory();
        $path = $this->directory . '/' . self::STATUS_FILE;
        $temporary = $path . '.tmp';
        $previous = umask(0o177);
        try {
            file_put_contents($temporary, json_encode([
                'state' => $state,
                'file' => $this->file,
                'step' => $step,
                'startedAt' => $this->startedAt,
                'updatedAt' => $this->now(),
                'message' => $message,
                'safetyBackup' => $this->safetyBackup,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            rename($temporary, $path);
        } finally {
            umask($previous);
        }
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0o700, true);
        }
        @chmod($this->directory, 0o700);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }
}
