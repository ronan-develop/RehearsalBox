<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Exception\AccountingValidationException;
use App\Group\Exception\InvalidUploadException;
use App\Group\Service\ImageSanitizer;

/**
 * Stockage des relevés de compte (#362), hors de la racine web. Le type se juge sur le CONTENU de la COPIE stockée (jamais sur le nom ni
 * sur le type annoncé, jamais « contrôle puis ré-écriture ») ; un PDF doit être entier (en-tête et fin de fichier) et non chiffré ; une
 * image est ré-encodée (métadonnées et octets ajoutés disparaissent) et, sans l'extension GD, REFUSÉE : on ne stocke jamais une image
 * brute. Le nom stocké est aléatoire ; dossier en 0700 et fichiers en 0600. Un refus ne laisse rien sur le disque.
 */
final class StatementFileStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const IMAGES = ['image/jpeg', 'image/png'];

    public function __construct(private readonly string $directory, private readonly ImageSanitizer $images = new ImageSanitizer())
    {
    }

    /** @throws AccountingValidationException erreur sur le champ `file` */
    public function store(string $uploadedPath, int $declaredSize): StoredStatementFile
    {
        if ($declaredSize > self::MAX_BYTES || (filesize($uploadedPath) ?: 0) > self::MAX_BYTES) {
            throw self::refusal('Le fichier dépasse la taille maximale autorisée (10 Mo).');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw self::refusal('Impossible de préparer le stockage des relevés.');
        }

        $rawName = bin2hex(random_bytes(16));
        $raw = $this->pathOf($rawName);
        $paths = [$raw];
        try {
            if (!copy($uploadedPath, $raw)) {
                throw self::refusal('Échec de l\'enregistrement du fichier.');
            }
            chmod($raw, 0600);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($raw);
            $finalName = $rawName;
            if ($mime === 'application/pdf') {
                $this->assertWholePdf($raw);
            } elseif (is_string($mime) && in_array($mime, self::IMAGES, true)) {
                $finalName = bin2hex(random_bytes(16));
                $paths[] = $this->pathOf($finalName);
                $this->reencode($raw, $mime, $this->pathOf($finalName));
                unlink($raw);
                $paths = [$this->pathOf($finalName)];
            } else {
                throw self::refusal('Type de fichier non autorisé (PDF, JPEG, PNG uniquement).');
            }
            $final = $this->pathOf($finalName);
            $size = filesize($final);
            $hash = hash_file('sha256', $final);
            if ($size === false || $size < 1 || $hash === false) {
                throw self::refusal('Le fichier est vide.');
            }

            return new StoredStatementFile($finalName, (string) $mime, $size, $hash);
        } catch (\Throwable $e) {
            $this->discard(...$paths);

            throw $e;
        }
    }

    public function pathOf(string $storedName): string
    {
        return $this->directory . '/' . $storedName;
    }

    /** Retire un fichier du stockage (dépôt annulé après la copie) ; sans effet si le nom n'a pas la forme d'un nom aléatoire. */
    public function remove(string $storedName): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $storedName) === 1) {
            $this->discard($this->pathOf($storedName));
        }
    }

    private function discard(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function reencode(string $source, string $mime, string $destination): void
    {
        if (!extension_loaded('gd')) {
            throw self::refusal('Les images ne peuvent pas être traitées sur ce serveur : déposez un PDF.');
        }
        try {
            $this->images->reencode($source, $mime, $destination);
            @chmod($destination, 0600);
        } catch (InvalidUploadException $e) {
            throw self::refusal($e->getMessage());
        }
    }

    private function assertWholePdf(string $path): void
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw self::refusal('Fichier illisible.');
        }
        $head = (string) fread($handle, 8);
        fseek($handle, max(0, $size - 2048));
        $tail = (string) fread($handle, 2048);
        fclose($handle);
        if (!str_starts_with($head, '%PDF-') || !str_contains($tail, '%%EOF')) {
            throw self::refusal('Ce PDF est incomplet : redéposez le fichier entier.');
        }
        if (str_contains($tail, '/Encrypt')) {
            throw self::refusal('Les PDF chiffrés ne sont pas acceptés : déposez une version non protégée.');
        }
    }

    private static function refusal(string $message): AccountingValidationException
    {
        return new AccountingValidationException(['file' => $message]);
    }
}
