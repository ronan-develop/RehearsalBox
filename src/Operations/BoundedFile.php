<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Lecture bornée d'un fichier de l'exploitation (#375) : jamais un lien symbolique ni autre chose qu'un fichier régulier, jamais
 * plus de $maxBytes lus (la fin d'un journal), absent ou illisible => null. Aucune exception : un relevé manquant n'empêche pas la page.
 */
final class BoundedFile
{
    public const DEFAULT_BYTES = 65536;

    public static function mtime(?string $path, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (!self::isPlainFile($path)) {
            return null;
        }
        $time = @filemtime((string) $path);

        return $time === false ? null : (new \DateTimeImmutable('@' . $time))->setTimezone($zone);
    }

    /** La fin du fichier (au plus $maxBytes) ; si elle est coupée, la première ligne, partielle, est jetée. */
    public static function tail(?string $path, int $maxBytes = self::DEFAULT_BYTES): ?string
    {
        if (!self::isPlainFile($path) || $maxBytes < 1) {
            return null;
        }
        $handle = @fopen((string) $path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $size = (int) (fstat($handle)['size'] ?? 0);
            $cut = $size > $maxBytes;
            if ($cut) {
                fseek($handle, $size - $maxBytes);
            }
            $content = (string) stream_get_contents($handle, $maxBytes);
        } finally {
            fclose($handle);
        }
        if ($cut) {
            $newline = strpos($content, "\n");
            $content = $newline === false ? '' : substr($content, $newline + 1);
        }

        return $content;
    }

    private static function isPlainFile(?string $path): bool
    {
        return $path !== null && !is_link($path) && is_file($path);
    }
}
