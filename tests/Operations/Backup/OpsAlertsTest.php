<?php

declare(strict_types=1);

namespace App\Tests\Operations\Backup;

use App\Backup\Restore\BackupCatalog;
use App\Metrics\Alert\AlertType;
use App\Metrics\Report\Thresholds;
use App\Operations\Backup\Declarations;
use App\Operations\Backup\KeyFileProbe;
use App\Operations\Backup\OpsAlerts;
use App\Operations\Backup\ProtectionHealthBuilder;
use App\Operations\OpsPaths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OpsAlertsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rb-OPSCANARY-' . bin2hex(random_bytes(4));
        foreach (['', '/shared', '/backups'] as $dir) {
            mkdir($this->base . $dir, 0o700);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/{shared,backups}/*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        foreach (['/shared', '/backups', ''] as $dir) {
            rmdir($this->base . $dir);
        }
    }

    private function alerts(): OpsAlerts
    {
        $clock = new MockClock('2026-10-11 12:00:00 UTC');
        $paths = new OpsPaths($this->base);

        return new OpsAlerts(new ProtectionHealthBuilder(
            new KeyFileProbe($this->base . '/shared/keys.json'),
            new Declarations($paths->declarations(), $clock),
            $paths,
            new BackupCatalog($this->base . '/backups'),
            new Thresholds(),
            $clock,
            new \DateTimeZone('UTC'),
        ));
    }

    private function validKey(): void
    {
        $key = $this->base . '/shared/keys.json';
        file_put_contents($key, (string) json_encode(['current' => 'k1', 'allow_plaintext' => false, 'keys' => ['k1' => base64_encode(random_bytes(32))]]));
        chmod($key, 0o600);
    }

    /** @return list<AlertType> */
    private function types(): array
    {
        return array_map(static fn ($a): AlertType => $a->type, $this->alerts()->alerts());
    }

    public function testAMissingKeyFileRaisesTheKeyAlert(): void
    {
        self::assertSame([AlertType::KeyFile], $this->types());
    }

    public function testAHealthySetupRaisesNothingAndOrangeNeverAlerts(): void
    {
        $this->validKey(); // clé jamais rangée dans KeePass et jamais copiée hors serveur = orange, pas d'alerte

        self::assertSame([], $this->types());
    }

    public function testATooOldOffsiteCopyRaisesTheOffsiteAlert(): void
    {
        $this->validKey();
        file_put_contents($this->base . '/shared/ops-declarations.json', (string) json_encode(['offsiteAt' => '2026-08-01T00:00:00Z']));

        self::assertSame([AlertType::OffsiteOld], $this->types());
    }

    public function testATaskThatRanWithoutProducingADumpRaisesTheBackupTaskAlert(): void
    {
        $this->validKey();
        file_put_contents($this->base . '/backups/db-20261009013000.sql.gz', 'x');
        file_put_contents($this->base . '/shared/backup.log', 'x');
        touch($this->base . '/shared/backup.log', (new \DateTimeImmutable('2026-10-11 01:30:10 UTC'))->getTimestamp());

        self::assertSame([AlertType::BackupTask], $this->types());
    }

    public function testMessagesAreFixedSentencesWithoutPathNorKeyName(): void
    {
        $this->validKey();
        file_put_contents($this->base . '/shared/ops-declarations.json', (string) json_encode(['offsiteAt' => '2026-08-01T00:00:00Z']));
        file_put_contents($this->base . '/backups/db-20261009013000.sql.gz', 'x');
        touch($this->base . '/shared/backup.log', (new \DateTimeImmutable('2026-10-11 01:30:10 UTC'))->getTimestamp());
        unlink($this->base . '/shared/keys.json');

        foreach ($this->alerts()->alerts() as $alert) {
            self::assertStringNotContainsString('OPSCANARY', $alert->message);
            self::assertStringNotContainsString('keys.json', $alert->message);
            self::assertSame('/admin/ops', $alert->page);
        }
        self::assertCount(3, $this->alerts()->alerts());
    }
}
