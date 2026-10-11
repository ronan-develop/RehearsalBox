<?php

declare(strict_types=1);

namespace App\Tests\Operations\Production;

use App\Backup\Restore\BackupCatalog;
use App\Metrics\Report\HealthStatus;
use App\Operations\Production\DeploymentLog;
use App\Operations\Production\MigrationStatus;
use App\Operations\Production\ProductionReportBuilder;
use App\Operations\Production\ReleaseInventory;
use PHPUnit\Framework\TestCase;

final class ProductionReportBuilderTest extends TestCase
{
    private const RELEASE = '20261011000000-ccc3333';

    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rb-OPSCANARY-prod-' . bin2hex(random_bytes(4));
        foreach (['', '/releases', '/backups', '/shared'] as $dir) {
            mkdir($this->base . $dir, 0o700);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/{releases/*/*,releases/*,backups/*,shared/*}', GLOB_BRACE) ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        foreach (['/releases', '/backups', '/shared', ''] as $dir) {
            @rmdir($this->base . $dir);
        }
    }

    /** @param list<string> $releases */
    private function builder(string $current = self::RELEASE, array $releases = [self::RELEASE], ?string $journal = null): ProductionReportBuilder
    {
        foreach ($releases as $id) {
            mkdir($this->base . '/releases/' . $id, 0o700);
            file_put_contents($this->base . '/releases/' . $id . '/RELEASE', $id);
        }
        if ($journal !== null) {
            file_put_contents($this->base . '/shared/deployments.jsonl', $journal . "\n");
        }
        $zone = new \DateTimeZone('Europe/Paris');

        return new ProductionReportBuilder(
            new DeploymentLog($this->base . '/shared/deployments.jsonl'),
            new ReleaseInventory($this->base . '/releases', $current, $zone),
            new MigrationStatus(null, $this->base),
            new BackupCatalog($this->base . '/backups'),
            $current,
            $zone,
        );
    }

    private function entry(bool $ok = true, bool $switched = true, string $release = self::RELEASE): string
    {
        return (string) json_encode(['v' => 1, 'release' => $release, 'commit' => 'ccc3333', 'startedAt' => '2026-10-11T09:30:00Z', 'durationS' => 90, 'outcome' => $ok ? 'ok' : 'failed', 'switched' => $switched, 'checks' => 'ci']);
    }

    public function testFirstDeploymentHasNoJournalNoRollbackTargetAndNoPreDeployDump(): void
    {
        $cards = $this->builder()->cards();

        self::assertSame('aucun journalisé', $cards['last_deployment']->value);
        self::assertNull($cards['last_deployment']->status);
        self::assertSame('impossible', $cards['rollback']->value);
        self::assertSame('absent', $cards['predeploy_dump']->value);
        self::assertSame(self::RELEASE, $cards['active_release']->value);
    }

    public function testSuccessfulDeployment(): void
    {
        $card = $this->builder(journal: $this->entry())->cards()['last_deployment'];

        self::assertSame(HealthStatus::Ok, $card->status);
        self::assertStringContainsString('heure du poste de dev', $card->hint);
    }

    public function testFailedBeforeTheSwitchLeavesProductionUnchanged(): void
    {
        $card = $this->builder(journal: $this->entry(ok: false, switched: false))->cards()['last_deployment'];

        self::assertSame(HealthStatus::Warning, $card->status);
        self::assertStringContainsString('production inchangée', $card->hint);
    }

    public function testFailedAfterTheSwitchIsCritical(): void
    {
        $card = $this->builder(journal: $this->entry(ok: false, switched: true))->cards()['last_deployment'];

        self::assertSame(HealthStatus::Critical, $card->status);
        self::assertSame('basculé, contrôle échoué', $card->value);
    }

    public function testRollbackTargetIsThePreviousRelease(): void
    {
        $cards = $this->builder(releases: ['20261010000000-bbb2222', self::RELEASE])->cards();

        self::assertSame('20261010000000-bbb2222', $cards['rollback']->value);
    }

    public function testThePreDeployDumpOfTheServedReleaseIsFound(): void
    {
        file_put_contents($this->base . '/backups/pre-' . self::RELEASE . '.sql.gz', 'x');

        self::assertSame('présent', $this->builder()->cards()['predeploy_dump']->value);
    }

    public function testUnreadableMigrationsAreUnknownNotAnError(): void
    {
        $card = $this->builder()->cards()['migrations'];

        self::assertSame(HealthStatus::Unknown, $card->status);
    }

    public function testMissingReleaseMarkerIsUnknown(): void
    {
        self::assertSame(HealthStatus::Unknown, $this->builder(current: '', releases: [])->cards()['active_release']->status);
    }

    public function testDurationsAreOldestFirst(): void
    {
        $journal = $this->entry(release: '20261009000000-aaa1111') . "\n" . str_replace('"durationS":90', '"durationS":120', $this->entry());

        $durations = $this->builder(journal: $journal)->durations();

        self::assertSame([90, 120], $durations['values']);
    }

    public function testNoCardContainsAServerPath(): void
    {
        foreach ($this->builder(journal: $this->entry())->cards() as $card) {
            self::assertStringNotContainsString('OPSCANARY', $card->label . $card->value . $card->hint);
        }
    }

    public function testTheMarkerIsReadAndValidated(): void
    {
        file_put_contents($this->base . '/RELEASE', self::RELEASE . "\n");
        self::assertSame(self::RELEASE, ReleaseInventory::markerIn($this->base . '/RELEASE'));

        file_put_contents($this->base . '/RELEASE', '../../etc/passwd');
        self::assertSame('', ReleaseInventory::markerIn($this->base . '/RELEASE'));
        self::assertSame('', ReleaseInventory::markerIn($this->base . '/absent'));
        unlink($this->base . '/RELEASE');
    }
}
