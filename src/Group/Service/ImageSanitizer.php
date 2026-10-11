<?php

declare(strict_types=1);

namespace App\Group\Service;

use App\Group\Exception\InvalidUploadException;

/**
 * Image envoyée par un utilisateur (#432) : décodée puis ré-encodée avec GD avant d'être stockée. Ce qui n'est pas de l'image
 * disparaît : données ajoutées après l'image (fichier « polyglotte »), segments cachés, métadonnées dont la POSITION GPS d'une photo.
 * L'orientation d'une photo de téléphone (EXIF) est appliquée avant de la retirer. Taille déclarée contrôlée AVANT le décodage
 * (une « bombe » de quelques octets déclarant des centaines de millions de pixels épuiserait la mémoire). Sans l'extension GD, le
 * fichier est stocké tel quel : le type réel (finfo) reste contrôlé en amont.
 */
final class ImageSanitizer
{
    public const MAX_PIXELS = 40_000_000;
    public const MAX_SIDE = 12000;
    private const JPEG_QUALITY = 90;

    /** @throws InvalidUploadException image illisible ou trop grande */
    public function reencode(string $source, string $mimeType, string $destination): void
    {
        if (!extension_loaded('gd')) {
            if (!copy($source, $destination)) {
                throw new InvalidUploadException("Échec de l'enregistrement du fichier.");
            }

            return;
        }

        $size = @getimagesize($source);
        if ($size === false) {
            throw new InvalidUploadException('Image illisible.');
        }
        if ($size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new InvalidUploadException('Image trop grande (au plus ' . self::MAX_SIDE . ' pixels de côté).');
        }

        $image = $mimeType === 'image/png' ? @imagecreatefrompng($source) : @imagecreatefromjpeg($source);
        if ($image === false) {
            throw new InvalidUploadException('Image illisible.');
        }

        if ($mimeType === 'image/jpeg') {
            $image = $this->upright($image, $source);
        }

        $saved = $mimeType === 'image/png' ? $this->savePng($image, $destination) : @imagejpeg($image, $destination, self::JPEG_QUALITY);
        imagedestroy($image);
        if (!$saved) {
            throw new InvalidUploadException("Échec de l'enregistrement du fichier.");
        }
    }

    private function savePng(\GdImage $image, string $destination): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return @imagepng($image, $destination, 6);
    }

    /** Applique l'orientation EXIF d'une photo (rotations 90/180/270 et miroirs simples) ; sans EXIF lisible, l'image est inchangée. */
    private function upright(\GdImage $image, string $source): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $result = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            2 => imageflip($image, IMG_FLIP_HORIZONTAL) ? $image : false,
            4 => imageflip($image, IMG_FLIP_VERTICAL) ? $image : false,
            default => $image,
        };

        return $result === false ? $image : $result;
    }
}
