<?php

declare(strict_types=1);

namespace App\Tests\Assets;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #470 : le logo #B27 est le favicon. Le navigateur demande `/favicon.ico` sans rien lui dire : le fichier est servi tel quel par le
 * serveur web (jamais par PHP), donc ce test lit le fichier lui-même : un vrai ICO, trois tailles, des images PNG valides, léger.
 */
final class FaviconTest extends TestCase
{
    private const FILE = __DIR__ . '/../../public/favicon.ico';

    private function bytes(): string
    {
        self::assertFileExists(self::FILE);

        return (string) file_get_contents(self::FILE);
    }

    /** @return list<array{width: int, height: int, size: int, offset: int}> */
    private function entries(string $ico): array
    {
        $header = unpack('vreserved/vtype/vcount', $ico);
        self::assertIsArray($header);
        self::assertSame(0, $header['reserved']);
        self::assertSame(1, $header['type'], 'type 1 = icône');

        $entries = [];
        for ($i = 0; $i < $header['count']; ++$i) {
            $e = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', $ico, 6 + 16 * $i);
            self::assertIsArray($e);
            $entries[] = ['width' => $e['width'] === 0 ? 256 : $e['width'], 'height' => $e['height'] === 0 ? 256 : $e['height'], 'size' => $e['size'], 'offset' => $e['offset']];
        }

        return $entries;
    }

    #[Test]
    public function testTheFaviconIsAnIcoWithThe16_32And48PixelSizes(): void
    {
        $sizes = array_map(static fn (array $e): int => $e['width'], $this->entries($this->bytes()));
        sort($sizes);

        self::assertSame([16, 32, 48], $sizes);
    }

    #[Test]
    public function testEachEntryIsAValidSquarePngOfTheDeclaredSize(): void
    {
        $ico = $this->bytes();

        foreach ($this->entries($ico) as $entry) {
            self::assertSame($entry['width'], $entry['height']);
            self::assertLessThanOrEqual(strlen($ico), $entry['offset'] + $entry['size'], 'entrée hors du fichier');
            $png = substr($ico, $entry['offset'], $entry['size']);
            self::assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8), 'image PNG');
            $ihdr = unpack('Nwidth/Nheight', $png, 16);
            self::assertIsArray($ihdr);
            self::assertSame($entry['width'], $ihdr['width']);
            self::assertSame($entry['height'], $ihdr['height']);
            self::assertSame("IEND\xaeB`\x82", substr($png, -8), 'PNG complet (chunk de fin)');
        }
    }

    #[Test]
    public function testTheFaviconIsLightAndNotBlank(): void
    {
        $ico = $this->bytes();

        self::assertLessThan(30_000, strlen($ico), 'chargé à chaque première visite');
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Extension gd absente : la lecture des pixels est impossible sur ce poste.');
        }
        // Plusieurs couleurs distinctes : pas une icône vide ni un aplat.
        foreach ($this->entries($ico) as $entry) {
            $image = imagecreatefromstring(substr($ico, $entry['offset'], $entry['size']));
            self::assertNotFalse($image);
            $colors = [];
            for ($x = 0; $x < $entry['width']; ++$x) {
                for ($y = 0; $y < $entry['height']; ++$y) {
                    $colors[imagecolorat($image, $x, $y)] = true;
                }
            }
            self::assertGreaterThan(3, count($colors), "{$entry['width']} px : l'icône n'est pas un aplat");
        }
    }
}
