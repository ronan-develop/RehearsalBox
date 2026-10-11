<?php

declare(strict_types=1);

namespace App\Tests\Operations\Backup;

use App\Operations\Backup\Declarations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DeclarationsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-decl-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function declarations(string $now = '2026-10-11 12:00:00'): Declarations
    {
        return new Declarations($this->dir . '/d.json', new MockClock($now));
    }

    public function testNothingDeclaredYet(): void
    {
        self::assertNull($this->declarations()->offsiteAt());
        self::assertNull($this->declarations()->keySavedAt());
    }

    public function testDeclarationIsStoredWithServerClockAndPrivateMode(): void
    {
        $this->declarations('2026-10-11 12:00:00')->declare(Declarations::OFFSITE);

        self::assertSame('2026-10-11T12:00:00Z', $this->declarations()->offsiteAt()?->format('Y-m-d\TH:i:s\Z'));
        self::assertNull($this->declarations()->keySavedAt());
        self::assertSame(0o600, fileperms($this->dir . '/d.json') & 0o777);
    }

    public function testTheOtherDeclarationIsKept(): void
    {
        $this->declarations('2026-10-10 08:00:00')->declare(Declarations::KEY_SAVED);
        $this->declarations('2026-10-11 12:00:00')->declare(Declarations::OFFSITE);

        self::assertNotNull($this->declarations()->keySavedAt());
        self::assertNotNull($this->declarations()->offsiteAt());
    }

    public function testUnknownDeclarationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->declarations()->declare('../../etc/passwd');
    }

    public function testGarbageContentGivesNull(): void
    {
        foreach (['{pas du json', '{"offsiteAt":"demain"}', '{"offsiteAt":["x"]}', '{"offsiteAt":"2026-13-45T99:00:00Z"}'] as $content) {
            file_put_contents($this->dir . '/d.json', $content);

            self::assertNull($this->declarations()->offsiteAt(), $content);
        }
    }

    public function testNoFileConfiguredCannotDeclare(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Declarations(null, new MockClock()))->declare(Declarations::OFFSITE);
    }

    public function testUnwritableLocationFailsCleanly(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Declarations($this->dir . '/absent/d.json', new MockClock()))->declare(Declarations::OFFSITE);
    }
}
