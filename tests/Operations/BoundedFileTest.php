<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Operations\BoundedFile;
use PHPUnit\Framework\TestCase;

final class BoundedFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-bounded-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testNullPathGivesNull(): void
    {
        self::assertNull(BoundedFile::tail(null));
        self::assertNull(BoundedFile::mtime(null, new \DateTimeZone('UTC')));
    }

    public function testMissingFileGivesNull(): void
    {
        self::assertNull(BoundedFile::tail($this->dir . '/absent.log'));
        self::assertNull(BoundedFile::mtime($this->dir . '/absent.log', new \DateTimeZone('UTC')));
    }

    public function testSmallFileIsReadWhole(): void
    {
        file_put_contents($this->dir . '/a.log', "un\ndeux\n");

        self::assertSame("un\ndeux\n", BoundedFile::tail($this->dir . '/a.log'));
    }

    public function testOnlyTheEndOfALargeFileIsReadAndTheCutLineDropped(): void
    {
        $lines = '';
        for ($i = 0; $i < 20000; ++$i) {
            $lines .= sprintf("ligne-%05d\n", $i);
        }
        file_put_contents($this->dir . '/big.log', $lines);

        $tail = BoundedFile::tail($this->dir . '/big.log', 1000);

        self::assertNotNull($tail);
        self::assertLessThanOrEqual(1000, strlen($tail));
        self::assertStringEndsWith("ligne-19999\n", $tail);
        self::assertStringStartsWith('ligne-', $tail, 'la première ligne, coupée, est jetée');
        self::assertSame(0, strlen($tail) % 12);
    }

    public function testSymlinkIsRefused(): void
    {
        file_put_contents($this->dir . '/real.log', 'secret');
        symlink($this->dir . '/real.log', $this->dir . '/link.log');

        self::assertNull(BoundedFile::tail($this->dir . '/link.log'));
        self::assertNull(BoundedFile::mtime($this->dir . '/link.log', new \DateTimeZone('UTC')));
    }

    public function testDirectoryIsRefused(): void
    {
        self::assertNull(BoundedFile::tail($this->dir));
    }

    public function testMtimeIsReturnedInTheGivenZone(): void
    {
        file_put_contents($this->dir . '/a.log', 'x');
        touch($this->dir . '/a.log', 1_800_000_000);

        $at = BoundedFile::mtime($this->dir . '/a.log', new \DateTimeZone('Europe/Paris'));

        self::assertNotNull($at);
        self::assertSame(1_800_000_000, $at->getTimestamp());
        self::assertSame('Europe/Paris', $at->getTimezone()->getName());
    }
}
