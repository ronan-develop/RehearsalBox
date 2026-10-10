<?php

declare(strict_types=1);

namespace App\Tests\Backup\Restore;

use App\Backup\Restore\RestoreStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** #241 : l'état d'une restauration vit dans un fichier (la base, elle, est en cours de remplacement) ; un seul à la fois. */
final class RestoreStatusTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/restore-status-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        is_dir($this->dir) && rmdir($this->dir);
    }

    private function newStatus(): RestoreStatus
    {
        return new RestoreStatus($this->dir, new MockClock('2026-10-20 10:00:00'));
    }

    #[Test]
    public function testNothingIsReportedBeforeAnyRestoration(): void
    {
        self::assertNull($this->newStatus()->read());
        self::assertFalse($this->newStatus()->isRunning());
    }

    #[Test]
    public function testTheLifecycleIsRecordedStepByStepAndFinishedWithTheSafetyBackup(): void
    {
        $status = $this->newStatus();

        $status->begin('db-20261020030000.sql.gz');
        $status->step('import');
        self::assertSame(['state' => 'running', 'file' => 'db-20261020030000.sql.gz', 'step' => 'import', 'startedAt' => '2026-10-20T10:00:00+00:00', 'updatedAt' => '2026-10-20T10:00:00+00:00', 'message' => '', 'safetyBackup' => ''], $status->read());

        $status->finish('pre-20261020100000-avant-restauration.sql.gz');
        $read = $status->read();
        self::assertSame('done', $read['state']);
        self::assertSame('pre-20261020100000-avant-restauration.sql.gz', $read['safetyBackup']);
    }

    #[Test]
    public function testAFailureKeepsItsMessage(): void
    {
        $status = $this->newStatus();
        $status->begin('db-20261020030000.sql.gz');
        $status->fail('Import raté.', 'pre-x.sql.gz');

        $read = $status->read();
        self::assertSame('failed', $read['state']);
        self::assertSame('Import raté.', $read['message']);
        self::assertSame('pre-x.sql.gz', $read['safetyBackup']);
    }

    #[Test]
    public function testOnlyOneRestorationCanHoldTheLockAndItIsReleasedWithTheHolder(): void
    {
        $first = $this->newStatus();
        $second = $this->newStatus();

        self::assertTrue($first->acquire());
        self::assertTrue($second->isRunning(), 'verrou détenu par la première');
        self::assertFalse($second->acquire());

        $first->release();
        self::assertFalse($second->isRunning());
        self::assertTrue($second->acquire());
        $second->release();
    }

    #[Test]
    public function testTheStatusFileIsPrivateAndAnUnreadableOneIsIgnored(): void
    {
        $status = $this->newStatus();
        $status->begin('db-20261020030000.sql.gz');

        self::assertSame('600', substr(sprintf('%o', fileperms($this->dir . '/restore-status.json')), -3));
        self::assertSame('700', substr(sprintf('%o', fileperms($this->dir)), -3));

        file_put_contents($this->dir . '/restore-status.json', '{pas du json');
        self::assertNull($status->read());
    }
}
