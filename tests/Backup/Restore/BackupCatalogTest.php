<?php

declare(strict_types=1);

namespace App\Tests\Backup\Restore;

use App\Backup\Restore\BackupCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #241 : le catalogue ne liste que les vraies sauvegardes `db-` et `pre-` d'un dossier, du plus récent au plus ancien. */
final class BackupCatalogTest extends TestCase
{
    private string $root;
    private string $directory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . uniqid('backup-catalog-', true);
        $this->directory = $this->root . '/backups';
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    #[Test]
    public function testTheNewestComesFirstAndTiesAreSortedByNameDesc(): void
    {
        $this->write('db-20261001033000.sql.gz', 'a');
        $this->write('db-20261003033000.sql.gz', 'a');
        $this->write('pre-20261003033000-abc1234.sql.gz', 'a');
        $this->write('db-20261002033000.sql.gz', 'a');

        $files = array_map(static fn ($entry) => $entry->file, (new BackupCatalog($this->directory))->list());

        self::assertSame([
            'pre-20261003033000-abc1234.sql.gz',
            'db-20261003033000.sql.gz',
            'db-20261002033000.sql.gz',
            'db-20261001033000.sql.gz',
        ], $files);
    }

    #[Test]
    public function testDailyAndPreDeployKindsAndLabelsAreSet(): void
    {
        $this->write('db-20261001033000.sql.gz', 'a');
        $this->write('pre-20261002120000-abc1234.sql.gz', 'a');

        $entries = (new BackupCatalog($this->directory))->list();

        self::assertSame('pre-20261002120000-abc1234.sql.gz', $entries[0]->file);
        self::assertSame('pre-deploy', $entries[0]->kind);
        self::assertSame('20261002120000-abc1234', $entries[0]->label);
        self::assertSame('2026-10-02 12:00:00', $entries[0]->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $entries[0]->createdAt->getTimezone()->getName());

        self::assertSame('db-20261001033000.sql.gz', $entries[1]->file);
        self::assertSame('daily', $entries[1]->kind);
        self::assertSame('', $entries[1]->label);
    }

    #[Test]
    public function testPartialDumpsAndUnknownFilesAreIgnored(): void
    {
        $this->write('db-20261001033000.sql.gz', 'a');
        $this->write('db-20261002033000.sql.gz.partial', 'a');
        $this->write('notes.txt', 'a');

        $files = array_map(static fn ($entry) => $entry->file, (new BackupCatalog($this->directory))->list());

        self::assertSame(['db-20261001033000.sql.gz'], $files);
    }

    #[Test]
    public function testAnInvalidDateIsIgnored(): void
    {
        $this->write('db-20269999999999.sql.gz', 'a');
        $this->write('db-20261001033000.sql.gz', 'a');

        $files = array_map(static fn ($entry) => $entry->file, (new BackupCatalog($this->directory))->list());

        self::assertSame(['db-20261001033000.sql.gz'], $files);
    }

    #[Test]
    public function testDirectoriesAndSymbolicLinksAreIgnored(): void
    {
        mkdir($this->directory . '/db-20261001033000.sql.gz');
        $this->write('source.sql.gz', 'a');
        symlink($this->root . '/source.sql.gz', $this->directory . '/db-20261002033000.sql.gz');

        self::assertSame([], (new BackupCatalog($this->directory))->list());
    }

    #[Test]
    public function testAMissingDirectoryGivesAnEmptyList(): void
    {
        self::assertSame([], (new BackupCatalog($this->root . '/absent'))->list());
    }

    #[Test]
    public function testByteSizeIsTheRealFileSize(): void
    {
        $this->write('db-20261001033000.sql.gz', str_repeat('x', 1234));

        $entries = (new BackupCatalog($this->directory))->list();

        self::assertSame(1234, $entries[0]->bytes);
    }

    #[Test]
    public function testFindReturnsTheExactEntry(): void
    {
        $this->write('pre-20261002120000-abc1234.sql.gz', 'a');

        $entry = (new BackupCatalog($this->directory))->find('pre-20261002120000-abc1234.sql.gz');

        self::assertNotNull($entry);
        self::assertSame('pre-deploy', $entry->kind);
    }

    #[Test]
    public function testFindRefusesPathsAndUnknownNames(): void
    {
        $this->write('db-20261001033000.sql.gz', 'a');
        $this->write('../x', 'a');

        $catalog = new BackupCatalog($this->directory);

        self::assertNull($catalog->find('../x'));
        self::assertNull($catalog->find('../backups/db-20261001033000.sql.gz'));
        self::assertNull($catalog->find('db-1.sql.gz'));
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->directory . '/' . $name, $content);
    }

    /** Supprime sans suivre les liens symboliques (jamais de récursion dans une cible de lien). */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
