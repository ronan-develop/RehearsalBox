<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupException;
use App\Backup\DumpVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #167 : un dump compressé tronqué ou vide ne doit jamais passer pour une sauvegarde valide. */
final class DumpVerifierTest extends TestCase
{
    private const END = '-- Dump completed on 2026-10-10 14:55:29';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dump-verifier-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** Écrit un fichier .gz contenant $content compressé (ou tel quel si $compressed est faux). */
    private function dump(string $content, bool $compressed = true): string
    {
        $path = $this->dir . '/dump-' . bin2hex(random_bytes(4)) . '.sql.gz';
        file_put_contents($path, $compressed ? gzencode($content) : $content);

        return $path;
    }

    private function validDump(): string
    {
        return "-- MariaDB dump\n\n"
            . "CREATE TABLE `a` (\n  `id` int\n);\n"
            . "INSERT INTO `a` VALUES (1);\n"
            . "CREATE TABLE `b` (\n  `id` int\n);\n"
            . "  CREATE TABLE `indented` (`id` int); -- pas en début de ligne\n"
            . "CREATE TABLE `c` (\n  `id` int\n);\n\n"
            . self::END . "\n";
    }

    #[Test]
    public function testAMissingFileIsRejected(): void
    {
        $this->expectException(BackupException::class);

        (new DumpVerifier())->verify($this->dir . '/absent.sql.gz');
    }

    #[Test]
    public function testAPlainTextFileThatIsNotGzipIsRejected(): void
    {
        $path = $this->dump($this->validDump(), false);

        $this->expectException(BackupException::class);

        (new DumpVerifier())->verify($path);
    }

    #[Test]
    public function testAnEmptyDecompressedDumpIsRejected(): void
    {
        $path = $this->dump('');

        $this->expectException(BackupException::class);

        (new DumpVerifier())->verify($path);
    }

    #[Test]
    public function testADumpWithoutAnyCreateTableIsRejected(): void
    {
        $path = $this->dump("-- MariaDB dump\n\n-- rien à voir\n\n" . self::END . "\n");

        $this->expectException(BackupException::class);

        (new DumpVerifier())->verify($path);
    }

    #[Test]
    public function testATruncatedDumpWithoutTheEndLineIsRejected(): void
    {
        $path = $this->dump("CREATE TABLE `a` (\n  `id` int\n);\nINSERT INTO `a` VALUES (1);\nINSERT INTO `a` VA");

        $this->expectException(BackupException::class);

        (new DumpVerifier())->verify($path);
    }

    #[Test]
    public function testAValidDumpReturnsItsNumberOfTables(): void
    {
        $path = $this->dump($this->validDump());

        self::assertSame(3, (new DumpVerifier())->verify($path));
    }

    #[Test]
    public function testAValidDumpWithoutTrailingNewlineIsAccepted(): void
    {
        $path = $this->dump("CREATE TABLE `a` (\n  `id` int\n);\n\n" . self::END);

        self::assertSame(1, (new DumpVerifier())->verify($path));
    }

    #[Test]
    public function testTheErrorMessageNeverContainsDumpContentOrPath(): void
    {
        $path = $this->dump("CREATE TABLE `a` (`id` int);\nINSERT INTO `a` VALUES ('secret');\n");

        try {
            (new DumpVerifier())->verify($path);
            self::fail('Une exception BackupException était attendue.');
        } catch (BackupException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString($this->dir, $e->getMessage());
            self::assertStringContainsString('Dump tronqué', $e->getMessage());
        }
    }
}
