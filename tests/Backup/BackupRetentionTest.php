<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupRetention;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #167 : la rotation ne supprime que les plus anciennes sauvegardes de SON type, jamais autre chose. */
final class BackupRetentionTest extends TestCase
{
    #[Test]
    public function testItKeepsTheNewestAndReturnsTheOldestOnesToDelete(): void
    {
        $files = ['db-20261001033000.sql.gz', 'db-20261003033000.sql.gz', 'db-20261002033000.sql.gz', 'db-20261004033000.sql.gz'];

        self::assertSame(['db-20261002033000.sql.gz', 'db-20261001033000.sql.gz'], (new BackupRetention('db', 2))->expired($files));
    }

    #[Test]
    public function testNothingIsDeletedWhileThereAreNoMoreThanTheLimit(): void
    {
        self::assertSame([], (new BackupRetention('db', 3))->expired(['db-20261001033000.sql.gz', 'db-20261002033000.sql.gz', 'db-20261003033000.sql.gz']));
        self::assertSame([], (new BackupRetention('db', 3))->expired([]));
    }

    #[Test]
    public function testOnlyTheFilesOfItsOwnKindAreConsidered(): void
    {
        $files = [
            'db-20261001033000.sql.gz', 'db-20261002033000.sql.gz', 'db-20261003033000.sql.gz',
            'pre-20261001120000-abc1234.sql.gz', 'pre-20261002120000-def5678.sql.gz',
        ];

        self::assertSame(['db-20261001033000.sql.gz'], (new BackupRetention('db', 2))->expired($files));
        self::assertSame(['pre-20261001120000-abc1234.sql.gz'], (new BackupRetention('pre', 1))->expired($files));
    }

    #[Test]
    public function testUnknownFilesAndPartialDumpsAreNeverTouched(): void
    {
        $files = [
            'db-20261001033000.sql.gz', 'db-20261002033000.sql.gz',
            'notes.txt', 'db-20261001033000.sql.gz.partial', 'db-latest.sql.gz', 'xdb-20260101000000.sql.gz', 'db-20261001033000.sql', '.htaccess',
        ];

        self::assertSame(['db-20261001033000.sql.gz'], (new BackupRetention('db', 1))->expired($files));
    }

    #[Test]
    public function testTheOrderComesFromTheTimestampInTheNameNotFromTheSuffix(): void
    {
        $files = ['pre-20261002120000-zzzzzzz.sql.gz', 'pre-20261003120000-aaaaaaa.sql.gz', 'pre-20261001120000-mmmmmmm.sql.gz'];

        self::assertSame(['pre-20261002120000-zzzzzzz.sql.gz', 'pre-20261001120000-mmmmmmm.sql.gz'], (new BackupRetention('pre', 1))->expired($files));
    }

    #[Test]
    public function testRetentionBelowOneIsRefusedSoNeverEverythingIsDeleted(): void
    {
        foreach ([0, -3] as $keep) {
            try {
                new BackupRetention('db', $keep);
                self::fail('Refus attendu');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testAnInvalidPrefixIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BackupRetention('../db', 3);
    }
}
