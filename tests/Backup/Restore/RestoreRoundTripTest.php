<?php

declare(strict_types=1);

namespace App\Tests\Backup\Restore;

use App\Backup\DatabaseBackup;
use App\Backup\DumpVerifier;
use App\Backup\ProcessDumper;
use App\Backup\ProcessImporter;
use App\Backup\Restore\BackupCatalog;
use App\Backup\Restore\DatabaseRestore;
use App\Backup\Restore\RestoreStatus;
use App\Backup\Restore\SchemaTools;
use App\Tests\Database\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** #241 : aller-retour RÉEL (mariadb-dump / mariadb) sur deux schémas jetables : sauvegarde, modification, restauration, comparaison. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class RestoreRoundTripTest extends TestCase
{
    private const PROD = 'rehearsalbox_roundtrip_prod_test';
    private const SCRATCH = 'rehearsalbox_roundtrip_scratch_test';

    private \PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        foreach (['mariadb-dump', 'mariadb'] as $program) {
            exec('command -v ' . $program . ' 2>/dev/null', $found, $code);
            if ($code !== 0) {
                self::markTestSkipped("Le client « {$program} » n'est pas installé sur ce poste (aller-retour réel impossible).");
            }
        }
        $this->pdo = TestDatabase::connection();
        foreach ([self::PROD, self::SCRATCH] as $schema) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$schema}`");
            $this->pdo->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4");
        }
        $this->dir = sys_get_temp_dir() . '/restore-roundtrip-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return; // test ignoré avant la préparation (client absent)
        }
        foreach ([self::PROD, self::SCRATCH] as $schema) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$schema}`");
        }
        foreach (array_merge(glob($this->dir . '/state/*') ?: [], glob($this->dir . '/*') ?: []) as $file) {
            is_file($file) && unlink($file);
        }
        is_dir($this->dir . '/state') && rmdir($this->dir . '/state');
        is_dir($this->dir) && rmdir($this->dir);
    }

    /** @return array{host: string, port: string, name: string, user: string, password: string} */
    private function db(string $name): array
    {
        return [
            'host' => getenv('DB_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_TEST_PORT') ?: '3307',
            'name' => $name,
            'user' => getenv('DB_TEST_USER') ?: 'root',
            'password' => getenv('DB_TEST_PASSWORD') ?: 'root',
        ];
    }

    #[Test]
    public function testARealDumpIsRestoredOverModifiedDataAndTheSafetyBackupHoldsTheModifiedState(): void
    {
        $this->pdo->exec('CREATE TABLE `' . self::PROD . '`.notes (id INT PRIMARY KEY, body VARCHAR(100)) DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('INSERT INTO `' . self::PROD . "`.notes VALUES (1, 'répétition 🎸 mardi')");
        $clock = new MockClock('2026-10-20 10:00:00');
        $backup = new DatabaseBackup($this->dir, (new ProcessDumper($this->db(self::PROD)))->__invoke(...), new DumpVerifier(), $clock);
        $dump = $backup->run(DatabaseBackup::DAILY)['file'];

        $this->pdo->exec('DELETE FROM `' . self::PROD . '`.notes');
        $this->pdo->exec('INSERT INTO `' . self::PROD . "`.notes VALUES (2, 'état modifié')");

        $restore = new DatabaseRestore(
            new BackupCatalog($this->dir),
            new DumpVerifier(),
            static fn (string $label): string => $backup->run(DatabaseBackup::PRE_DEPLOY, $label)['file'],
            (new ProcessImporter($this->db(self::PROD)))->__invoke(...),
            new SchemaTools($this->pdo),
            new RestoreStatus($this->dir . '/state', $clock),
            self::PROD,
            self::SCRATCH,
            $clock,
            static fn (): string => '',
        );

        $result = $restore->restore($dump, self::PROD);

        $rows = $this->pdo->query('SELECT id, body FROM `' . self::PROD . '`.notes')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame([1 => 'répétition 🎸 mardi'], $rows, 'données du dump rétablies, accents et emoji compris');
        self::assertTrue($result['rehearsed']);
        self::assertSame([], (new SchemaTools($this->pdo))->tables(self::SCRATCH));
        self::assertFileExists($this->dir . '/' . $result['safetyBackup']);
        self::assertStringContainsString('état modifié', (string) gzdecode((string) file_get_contents($this->dir . '/' . $result['safetyBackup'])), 'le retour du retour contient l\'état d\'avant');
    }
}
