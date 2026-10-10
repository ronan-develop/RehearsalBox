<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupException;
use App\Backup\DatabaseBackup;
use App\Backup\DumpVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** #167 : une sauvegarde n'est gardée que COMPLÈTE ; la rotation ne s'applique qu'après une réussite ; rien n'est jamais écrasé. */
final class DatabaseBackupTest extends TestCase
{
    private string $dir;
    private MockClock $clock;
    /** @var list<string> */
    private array $destinations = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/db-backup-' . bin2hex(random_bytes(4));
        $this->clock = new MockClock('2026-10-20 03:30:00');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                is_file($file) && unlink($file);
            }
            rmdir($this->dir);
        }
    }

    private function validDump(): \Closure
    {
        return function (string $destination): void {
            $this->destinations[] = $destination;
            file_put_contents($destination, gzencode("-- dump\nCREATE TABLE a (id int);\nCREATE TABLE b (id int);\n\n-- Dump completed on 2026-10-11 03:30:00\n"));
        };
    }

    private function backup(?\Closure $dumper = null): DatabaseBackup
    {
        return new DatabaseBackup($this->dir, $dumper ?? $this->validDump(), new DumpVerifier(), $this->clock);
    }

    private function seed(string $name): void
    {
        is_dir($this->dir) || mkdir($this->dir, 0o700);
        file_put_contents($this->dir . '/' . $name, 'ancienne sauvegarde');
    }

    /** @return list<string> */
    private function names(): array
    {
        $names = array_map('basename', glob($this->dir . '/*') ?: []);
        sort($names);

        return $names;
    }

    #[Test]
    public function testItCreatesThePrivateFolderAndAPrivateTimestampedDump(): void
    {
        $result = $this->backup()->run(DatabaseBackup::DAILY);

        self::assertSame('db-20261020033000.sql.gz', $result['file']);
        self::assertSame(2, $result['tables']);
        self::assertSame(['db-20261020033000.sql.gz'], $this->names());
        self::assertSame(0o700, fileperms($this->dir) & 0o777);
        self::assertSame(0o600, fileperms($this->dir . '/db-20261020033000.sql.gz') & 0o777);
        self::assertSame(filesize($this->dir . '/db-20261020033000.sql.gz'), $result['bytes']);
        self::assertSame([], $result['removed']);
    }

    #[Test]
    public function testTheDumpIsBuiltInAPartialFileThatNeverRemains(): void
    {
        $this->backup()->run(DatabaseBackup::DAILY);

        self::assertStringEndsWith('.partial', $this->destinations[0], 'écrit dans un fichier provisoire, renommé seulement une fois vérifié');
        self::assertCount(1, $this->names());
    }

    #[Test]
    public function testBeforeDeployDumpsAreNamedAfterTheRelease(): void
    {
        $result = $this->backup()->run(DatabaseBackup::PRE_DEPLOY, '20261011033000-abc1234');

        self::assertSame('pre-20261011033000-abc1234.sql.gz', $result['file']);
    }

    #[Test]
    public function testALabelThatCouldEscapeTheFolderOrBreakTheNamingIsRefused(): void
    {
        foreach (['../evil', 'a b', '20261011033000/../x', '', 'é'] as $label) {
            try {
                $this->backup()->run(DatabaseBackup::PRE_DEPLOY, $label);
                self::fail("Refus attendu pour « {$label} »");
            } catch (BackupException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->names());
    }

    #[Test]
    public function testABeforeDeployDumpWithoutLabelIsRefused(): void
    {
        $this->expectException(BackupException::class);
        $this->backup()->run(DatabaseBackup::PRE_DEPLOY);
    }

    #[Test]
    public function testAnIncompleteDumpIsDiscardedAndNoGoodBackupIsRotatedAway(): void
    {
        for ($day = 1; $day <= 14; ++$day) {
            $this->seed(sprintf('db-202610%02d033000.sql.gz', $day));
        }
        $truncated = function (string $destination): void {
            file_put_contents($destination, gzencode("-- dump\nCREATE TABLE a (id int);\n")); // pas de « Dump completed »
        };

        try {
            $this->backup($truncated)->run(DatabaseBackup::DAILY);
            self::fail('Échec attendu');
        } catch (BackupException $e) {
            self::assertStringContainsString('tronqué', $e->getMessage());
        }

        self::assertCount(14, $this->names(), 'les 14 bonnes sauvegardes sont intactes, rien de provisoire ne reste');
        self::assertSame([], array_filter($this->names(), static fn (string $n): bool => str_contains($n, 'partial')));
    }

    #[Test]
    public function testAFailingDumperLeavesNothingBehind(): void
    {
        $failing = function (string $destination): void {
            file_put_contents($destination, 'début');
            throw new BackupException('Le dump a échoué (code 2).');
        };

        try {
            $this->backup($failing)->run(DatabaseBackup::DAILY);
            self::fail('Échec attendu');
        } catch (BackupException) {
        }

        self::assertSame([], $this->names());
    }

    #[Test]
    public function testRotationKeepsFourteenDailyDumpsAndNeverTouchesOtherKinds(): void
    {
        for ($day = 1; $day <= 14; ++$day) {
            $this->seed(sprintf('db-202610%02d033000.sql.gz', $day));
        }
        $this->seed('pre-20261001120000-abc1234.sql.gz');
        $this->seed('notes.txt');

        $result = $this->backup()->run(DatabaseBackup::DAILY);

        self::assertSame(['db-20261001033000.sql.gz'], $result['removed']);
        self::assertContains('db-20261020033000.sql.gz', $this->names());
        self::assertNotContains('db-20261001033000.sql.gz', $this->names());
        self::assertContains('pre-20261001120000-abc1234.sql.gz', $this->names());
        self::assertContains('notes.txt', $this->names());
        self::assertCount(14, array_filter($this->names(), static fn (string $n): bool => str_starts_with($n, 'db-')));
    }

    #[Test]
    public function testBeforeDeployRotationKeepsSeven(): void
    {
        for ($i = 1; $i <= 7; ++$i) {
            $this->seed(sprintf('pre-202610%02d120000-abc%04d.sql.gz', $i, $i));
        }

        $result = $this->backup()->run(DatabaseBackup::PRE_DEPLOY, '20261011033000-def5678');

        self::assertSame(['pre-20261001120000-abc0001.sql.gz'], $result['removed']);
    }

    #[Test]
    public function testItNeverOverwritesAnExistingBackup(): void
    {
        $this->seed('db-20261020033000.sql.gz');

        try {
            $this->backup()->run(DatabaseBackup::DAILY);
            self::fail('Refus attendu');
        } catch (BackupException $e) {
            self::assertStringContainsString('existe déjà', $e->getMessage());
        }

        self::assertSame('ancienne sauvegarde', file_get_contents($this->dir . '/db-20261020033000.sql.gz'));
    }

    #[Test]
    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(BackupException::class);
        $this->backup()->run('hebdo');
    }
}
