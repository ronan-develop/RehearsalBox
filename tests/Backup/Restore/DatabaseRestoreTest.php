<?php

declare(strict_types=1);

namespace App\Tests\Backup\Restore;

use App\Backup\BackupException;
use App\Backup\DumpVerifier;
use App\Backup\Restore\BackupCatalog;
use App\Backup\Restore\DatabaseRestore;
use App\Backup\Restore\RestoreStatus;
use App\Backup\Restore\SchemaTools;
use App\Tests\Database\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * #241 : la restauration complète ne détruit rien avant d'avoir tout vérifié, fait un dump de sauvegarde AVANT de vider, et dit comment
 * revenir en arrière si l'import échoue. Deux schémas jetables jouent « production » et « base temporaire » ; l'import est simulé.
 */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class DatabaseRestoreTest extends TestCase
{
    private const PROD = 'rehearsalbox_restore_prod_test';
    private const SCRATCH = 'rehearsalbox_restore_scratch_test';
    private const DUMP = 'db-20261019030000.sql.gz';

    private \PDO $pdo;
    private string $dir;
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        foreach ([self::PROD, self::SCRATCH] as $schema) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$schema}`");
            $this->pdo->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4");
        }
        $this->pdo->exec('CREATE TABLE `' . self::PROD . '`.current_data (id INT PRIMARY KEY)');
        $this->dir = sys_get_temp_dir() . '/db-restore-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
        file_put_contents($this->dir . '/' . self::DUMP, gzencode("CREATE TABLE a (id int);\nCREATE TABLE b (id int);\n\n-- Dump completed on 2026-10-19 03:00:00\n"));
    }

    protected function tearDown(): void
    {
        foreach ([self::PROD, self::SCRATCH] as $schema) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$schema}`");
        }
        foreach (glob($this->dir . '/state/*') ?: [] as $file) {
            unlink($file);
        }
        is_dir($this->dir . '/state') && rmdir($this->dir . '/state');
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @param \Closure(string, string): void|null $importer */
    private function restore(?\Closure $importer = null, ?\Closure $safety = null, ?string $scratch = self::SCRATCH): DatabaseRestore
    {
        return new DatabaseRestore(
            new BackupCatalog($this->dir),
            new DumpVerifier(),
            $safety ?? function (string $label): string {
                $this->calls[] = 'safety:' . $label;

                return 'pre-' . $label . '.sql.gz';
            },
            $importer ?? function (string $path, string $schema): void {
                $this->calls[] = 'import:' . basename($path) . '>' . $schema;
                $this->pdo->exec("CREATE TABLE `{$schema}`.a (id INT)");
                $this->pdo->exec("CREATE TABLE `{$schema}`.b (id INT)");
            },
            new SchemaTools($this->pdo),
            new RestoreStatus($this->dir . '/state', new MockClock('2026-10-20 10:00:00')),
            self::PROD,
            $scratch,
            new MockClock('2026-10-20 10:00:00'),
            function (): string {
                $this->calls[] = 'follow-up';

                return 'Migrations : à jour.';
            },
        );
    }

    /** @return list<string> */
    private function tables(string $schema): array
    {
        return (new SchemaTools($this->pdo))->tables($schema);
    }

    #[Test]
    public function testItRehearsesInTheScratchBaseThenBacksUpThenReplacesTheProductionTables(): void
    {
        $result = $this->restore()->restore(self::DUMP, self::PROD);

        self::assertSame(['import:' . self::DUMP . '>' . self::SCRATCH, 'safety:20261020100000-avant-restauration', 'import:' . self::DUMP . '>' . self::PROD, 'follow-up'], $this->calls);
        self::assertSame(['a', 'b'], $this->tables(self::PROD), 'les anciennes tables ont disparu');
        self::assertSame([], $this->tables(self::SCRATCH), 'la base temporaire est vidée après l\'essai');
        self::assertSame(['safetyBackup' => 'pre-20261020100000-avant-restauration.sql.gz', 'tables' => 2, 'rehearsed' => true, 'notes' => 'Migrations : à jour.'], $result);
        $state = (new RestoreStatus($this->dir . '/state', new MockClock('now')))->read();
        self::assertSame('done', $state['state'] ?? null);
        self::assertSame('Migrations : à jour.', $state['message'] ?? null, 'les notes de suite sont gardées avec le résultat');
    }

    #[Test]
    public function testWithoutTheExactBaseNameNothingIsTouched(): void
    {
        foreach (['', 'oui', self::SCRATCH] as $confirmation) {
            try {
                $this->restore()->restore(self::DUMP, $confirmation);
                self::fail('Refus attendu');
            } catch (BackupException) {
            }
        }

        self::assertSame([], $this->calls);
        self::assertSame(['current_data'], $this->tables(self::PROD));
    }

    #[Test]
    public function testAnUnknownOrNonConformingDumpIsRefusedBeforeAnything(): void
    {
        file_put_contents($this->dir . '/db-20261018030000.sql.gz', gzencode("CREATE TABLE a (id int);\n"));

        foreach (['absent.sql.gz', '../etc/passwd', 'db-20261018030000.sql.gz'] as $file) {
            try {
                $this->restore()->restore($file, self::PROD);
                self::fail('Refus attendu');
            } catch (BackupException) {
            }
        }

        self::assertSame([], $this->calls);
        self::assertSame(['current_data'], $this->tables(self::PROD));
    }

    #[Test]
    public function testAFailedRehearsalLeavesProductionUntouchedAndEmptiesTheScratchBase(): void
    {
        $importer = function (string $path, string $schema): void {
            $this->pdo->exec("CREATE TABLE `{$schema}`.a (id INT)"); // une table sur deux : import incomplet
        };

        try {
            $this->restore($importer)->restore(self::DUMP, self::PROD);
            self::fail('Échec attendu');
        } catch (BackupException) {
        }

        self::assertSame(['current_data'], $this->tables(self::PROD));
        self::assertSame([], $this->tables(self::SCRATCH));
        self::assertSame([], $this->calls, 'pas de dump de sauvegarde ni de vidage');
    }

    #[Test]
    public function testANonEmptyScratchBaseIsNeverUsed(): void
    {
        $this->pdo->exec('CREATE TABLE `' . self::SCRATCH . '`.precious (id INT)');

        try {
            $this->restore()->restore(self::DUMP, self::PROD);
            self::fail('Refus attendu');
        } catch (BackupException) {
        }

        self::assertSame(['precious'], $this->tables(self::SCRATCH), 'rien n\'est supprimé dans la base temporaire');
        self::assertSame(['current_data'], $this->tables(self::PROD));
    }

    #[Test]
    public function testIfTheSafetyBackupFailsProductionIsNotEmptied(): void
    {
        $safety = static fn (string $label): string => throw new BackupException('Disque plein.');

        try {
            $this->restore(null, $safety)->restore(self::DUMP, self::PROD);
            self::fail('Échec attendu');
        } catch (BackupException $e) {
            self::assertStringContainsString('Disque plein', $e->getMessage());
        }

        self::assertSame(['current_data'], $this->tables(self::PROD));
    }

    #[Test]
    public function testAnImportFailingAfterTheEmptyingNamesTheBackupToPutBack(): void
    {
        $importer = function (string $path, string $schema): void {
            if ($schema === self::PROD) {
                throw new BackupException('L\'import a échoué (code 1).');
            }
            $this->pdo->exec("CREATE TABLE `{$schema}`.a (id INT)");
            $this->pdo->exec("CREATE TABLE `{$schema}`.b (id INT)");
        };

        try {
            $this->restore($importer)->restore(self::DUMP, self::PROD);
            self::fail('Échec attendu');
        } catch (BackupException $e) {
            self::assertStringContainsString('pre-20261020100000-avant-restauration.sql.gz', $e->getMessage());
        }

        $state = (new RestoreStatus($this->dir . '/state', new MockClock('now')))->read();
        self::assertSame('failed', $state['state'] ?? null);
        self::assertSame('pre-20261020100000-avant-restauration.sql.gz', $state['safetyBackup'] ?? null);
    }

    #[Test]
    public function testWithoutAScratchBaseTheRehearsalIsSkippedAndSaidSo(): void
    {
        $result = $this->restore(null, null, null)->restore(self::DUMP, self::PROD);

        self::assertFalse($result['rehearsed']);
        self::assertSame(['safety:20261020100000-avant-restauration', 'import:' . self::DUMP . '>' . self::PROD, 'follow-up'], $this->calls);
    }

    #[Test]
    public function testAnotherRestorationInProgressBlocksTheNewOne(): void
    {
        $other = new RestoreStatus($this->dir . '/state', new MockClock('now'));
        $other->acquire();

        try {
            $this->restore()->restore(self::DUMP, self::PROD);
            self::fail('Refus attendu');
        } catch (BackupException $e) {
            self::assertStringContainsString('déjà en cours', $e->getMessage());
        } finally {
            $other->release();
        }

        self::assertSame(['current_data'], $this->tables(self::PROD));
    }
}
