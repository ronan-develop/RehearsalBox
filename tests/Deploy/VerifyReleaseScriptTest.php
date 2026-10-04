<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** bin/verify-release.sh avec un faux `curl` : aucune requête réseau. */
final class VerifyReleaseScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-verify-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /** @return array{0: int, 1: string} */
    private function runVerify(string $curlOutput, string $expected = 'abc123def456'): array
    {
        file_put_contents($this->dir . '/curl', "#!/usr/bin/env bash\nprintf '%s' " . escapeshellarg($curlOutput) . "\n");
        chmod($this->dir . '/curl', 0755);

        $cmd = sprintf(
            'PATH=%s:$PATH RB_VERIFY_SLEEP=0 %s https://example.test/login %s 2 2>&1',
            escapeshellarg($this->dir),
            escapeshellarg(__DIR__ . '/../../bin/verify-release.sh'),
            escapeshellarg($expected),
        );
        exec($cmd, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    #[Test]
    public function testSucceedsWhenServedReleaseMatches(): void
    {
        [$code] = $this->runVerify("HTTP/2 200\r\nx-release: abc123def456\r\n\r\n");

        self::assertSame(0, $code);
    }

    #[Test]
    public function testFailsLoudlyWhenOldReleaseIsServed(): void
    {
        [$code, $out] = $this->runVerify("HTTP/2 200\r\nx-release: 000000000000\r\n\r\n");

        self::assertNotSame(0, $code);
        self::assertStringContainsString('ancienne release', $out);
    }

    #[Test]
    public function testFailsWhenMarkerIsAbsent(): void
    {
        [$code, $out] = $this->runVerify("HTTP/2 200\r\ncontent-type: text/html\r\n\r\n");

        self::assertNotSame(0, $code);
        self::assertStringContainsString('ancienne release', $out);
    }
}
