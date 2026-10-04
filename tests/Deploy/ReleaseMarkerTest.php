<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use App\Deploy\ReleaseMarker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReleaseMarkerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'rb-release-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    #[Test]
    public function testFingerprintIsOpaqueAndStable(): void
    {
        $release = '20261004151436-e047087';

        $fingerprint = ReleaseMarker::fingerprint($release);

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $fingerprint);
        self::assertSame($fingerprint, ReleaseMarker::fingerprint($release));
        self::assertStringNotContainsString('e047087', $fingerprint);
        self::assertNotSame($fingerprint, ReleaseMarker::fingerprint('20261004160000-aaaaaaa'));
    }

    #[Test]
    public function testFingerprintMatchesTheShellComputationUsedByDeploy(): void
    {
        $release = '20261004151436-e047087';
        $shell = trim((string) shell_exec('printf %s ' . escapeshellarg($release) . ' | sha256sum | cut -c1-12'));

        self::assertSame($shell, ReleaseMarker::fingerprint($release));
    }

    #[Test]
    public function testFromFileReturnsFingerprintOfTheReleaseWritten(): void
    {
        file_put_contents($this->file, "20261004151436-e047087\n");

        self::assertSame(ReleaseMarker::fingerprint('20261004151436-e047087'), ReleaseMarker::fromFile($this->file));
    }

    #[Test]
    public function testFromFileReturnsNullWhenFileMissingOrEmpty(): void
    {
        self::assertNull(ReleaseMarker::fromFile($this->file . '.absent'));

        file_put_contents($this->file, "  \n");
        self::assertNull(ReleaseMarker::fromFile($this->file));
    }
}
