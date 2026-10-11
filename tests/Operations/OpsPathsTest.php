<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Operations\OpsPaths;
use PHPUnit\Framework\TestCase;

final class OpsPathsTest extends TestCase
{
    public function testFixedNamesUnderTheBaseDirectory(): void
    {
        $paths = new OpsPaths('/home/x/rehearsalbox/');

        self::assertSame('/home/x/rehearsalbox/shared/backup.log', $paths->backupLog());
        self::assertSame('/home/x/rehearsalbox/shared/storage/logs/cron.log', $paths->cronLog());
        self::assertSame('/home/x/rehearsalbox/shared/storage/logs/collect.log', $paths->collectLog());
        self::assertSame('/home/x/rehearsalbox/shared/ops-declarations.json', $paths->declarations());
        self::assertSame('/home/x/rehearsalbox/shared/deployments.jsonl', $paths->deploymentsLog());
        self::assertSame('/home/x/rehearsalbox/releases', $paths->releasesDir());
    }

    public function testNoBaseDirectoryGivesNoPath(): void
    {
        foreach ([null, ''] as $base) {
            $paths = new OpsPaths($base);

            self::assertNull($paths->backupLog());
            self::assertNull($paths->cronLog());
            self::assertNull($paths->collectLog());
            self::assertNull($paths->declarations());
            self::assertNull($paths->deploymentsLog());
            self::assertNull($paths->releasesDir());
        }
    }
}
