<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Service\StatementFileStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : stockage d'un relevé : type contrôlé sur le contenu, copie stockée vérifiée, nom aléatoire, droits restreints, échec fermé. */
final class StatementFileStoreTest extends TestCase
{
    private string $dir;
    private string $tmp;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-statements-' . bin2hex(random_bytes(4));
        $this->tmp = sys_get_temp_dir() . '/rb-upload-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        @unlink($this->tmp);
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function put(string $content): string
    {
        file_put_contents($this->tmp, $content);

        return $this->tmp;
    }

    private function store(): StatementFileStore
    {
        return new StatementFileStore($this->dir);
    }

    private function expectRefusal(string $field, string $content, ?int $declared = null): void
    {
        $path = $this->put($content);
        try {
            $this->store()->store($path, $declared ?? strlen($content));
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
        self::assertSame([], glob($this->dir . '/*') ?: [], 'aucun fichier ne reste sur le disque');
    }

    #[Test]
    public function testAPdfIsStoredUnderARandomNameWithPrivateRights(): void
    {
        $stored = $this->store()->store($this->put(self::pdf()), strlen(self::pdf()));

        self::assertSame('application/pdf', $stored->mime);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $stored->storedName);
        self::assertSame(hash('sha256', self::pdf()), $stored->sha256);
        self::assertSame(strlen(self::pdf()), $stored->sizeBytes);
        $path = $this->store()->pathOf($stored->storedName);
        self::assertFileExists($path);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        self::assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));
    }

    #[Test]
    public function testAnImageIsReencodedWithoutItsMetadata(): void
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $polyglot = $png . '<?php system($_GET[0]); ?>';

        $stored = $this->store()->store($this->put($polyglot), strlen($polyglot));

        self::assertSame('image/png', $stored->mime);
        self::assertStringNotContainsString('<?php', (string) file_get_contents($this->store()->pathOf($stored->storedName)));
    }

    /** @return iterable<string, array{0: string}> */
    public static function refused(): iterable
    {
        yield 'script déguisé en PDF' => ["<?php echo 'x';"];
        yield 'HTML' => ['<html><script>alert(1)</script></html>'];
        yield 'fichier vide' => [''];
        yield 'PDF tronqué (pas de fin de fichier)' => ["%PDF-1.4\n1 0 obj\n<< >>\nendobj\n"];
        yield 'PDF chiffré' => ["%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< /Encrypt 5 0 R >>\n%%EOF\n"];
        yield 'GIF' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"];
    }

    #[Test]
    #[DataProvider('refused')]
    public function testAContentThatIsNotAWholeStatementIsRefusedWithNothingLeftOnDisk(string $content): void
    {
        $this->expectRefusal('file', $content);
    }

    #[Test]
    public function testATooLargeFileIsRefusedWhateverItsDeclaredSize(): void
    {
        $this->expectRefusal('file', self::pdf() . str_repeat('x', StatementFileStore::MAX_BYTES), 10);
    }

    #[Test]
    public function testABrokenImageIsRefusedNotStoredAsIs(): void
    {
        $this->expectRefusal('file', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 40));
    }
}
