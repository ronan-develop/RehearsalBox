<?php

declare(strict_types=1);

namespace App\Tests\Operations\Backup;

use App\Operations\Backup\KeyFileProbe;
use App\Operations\Backup\KeyFileState;
use PHPUnit\Framework\TestCase;

final class KeyFileProbeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-keyprobe-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function write(string $json, int $mode = 0o600): string
    {
        $path = $this->dir . '/keys.json';
        file_put_contents($path, $json);
        chmod($path, $mode);

        return $path;
    }

    private function validJson(bool $plaintext = false): string
    {
        return (string) json_encode(['current' => 'k1', 'allow_plaintext' => $plaintext, 'keys' => ['k1' => base64_encode(random_bytes(32)), 'k2' => base64_encode(random_bytes(32))]]);
    }

    public function testMissingFile(): void
    {
        self::assertSame(KeyFileState::Missing, (new KeyFileProbe($this->dir . '/none.json'))->inspect()['state']);
        self::assertSame(KeyFileState::Missing, (new KeyFileProbe(''))->inspect()['state']);
    }

    public function testTooOpenPermissionsAreReportedBeforeAnyRead(): void
    {
        $result = (new KeyFileProbe($this->write($this->validJson(), 0o644)))->inspect();

        self::assertSame(KeyFileState::TooOpen, $result['state']);
        self::assertSame(0, $result['keys']);
    }

    public function testCorruptedJsonIsUnreadable(): void
    {
        self::assertSame(KeyFileState::Unreadable, (new KeyFileProbe($this->write('{pas du json')))->inspect()['state']);
    }

    public function testValidFileGivesStateAndCountOnly(): void
    {
        $json = $this->validJson(true);
        $result = (new KeyFileProbe($this->write($json)))->inspect();

        self::assertSame(KeyFileState::Ok, $result['state']);
        self::assertSame(2, $result['keys']);
        self::assertTrue($result['transition']);
    }

    public function testNoKeyValueOrNameLeaksFromTheResult(): void
    {
        $json = $this->validJson();
        $keys = json_decode($json, true)['keys'];
        $exported = var_export((new KeyFileProbe($this->write($json)))->inspect(), true);

        foreach ($keys as $name => $value) {
            self::assertStringNotContainsString($value, $exported);
            self::assertStringNotContainsString("'" . $name . "'", $exported);
        }
    }

    public function testTheDeploymentSymlinkToTheSharedKeyFileIsFollowedLikeTheApplicationDoes(): void
    {
        $real = $this->write($this->validJson(true));
        symlink($real, $this->dir . '/link.json');

        $result = (new KeyFileProbe($this->dir . '/link.json'))->inspect();

        self::assertSame(KeyFileState::Ok, $result['state']);
        self::assertSame(2, $result['keys']);
    }

    public function testASymlinkToATooOpenFileIsReportedAsTooOpen(): void
    {
        $real = $this->write($this->validJson(), 0o644);
        symlink($real, $this->dir . '/link.json');

        self::assertSame(KeyFileState::TooOpen, (new KeyFileProbe($this->dir . '/link.json'))->inspect()['state']);
    }

    public function testADanglingSymlinkIsMissing(): void
    {
        symlink($this->dir . '/n-existe-pas.json', $this->dir . '/link.json');

        self::assertSame(KeyFileState::Missing, (new KeyFileProbe($this->dir . '/link.json'))->inspect()['state']);
    }
}
