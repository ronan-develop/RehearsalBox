<?php

declare(strict_types=1);

namespace App\Tests\Backup\Restore;

use App\Backup\BackupException;
use App\Backup\Restore\RestoreLauncher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #241 : la page lance bin/restore-db.php en processus DÉTACHÉ, sans shell, avec des arguments exacts. */
final class RestoreLauncherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/restore-launcher-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function fakePhp(string $body): string
    {
        $path = $this->dir . '/fake-php.sh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0o755);

        return $path;
    }

    private function waitFor(string $path): string
    {
        for ($i = 0; $i < 100 && trim((string) @file_get_contents($path)) === ''; ++$i) {
            usleep(50_000);
        }

        return trim((string) @file_get_contents($path));
    }

    #[Test]
    public function testItStartsTheScriptWithTheExactArgumentsAndNeverWaitsForIt(): void
    {
        $php = $this->fakePhp("echo \"\$@\" > {$this->dir}/args\nsleep 1\n");
        $launcher = new RestoreLauncher($php, '/app/bin/restore-db.php', '/home/x/backups', 'ma_base', 'ma_base_restore', $this->dir . '/restore.log', $this->dir);

        $started = microtime(true);
        $launcher->launch('db-20261019030000.sql.gz');

        self::assertLessThan(0.8, microtime(true) - $started, 'ne bloque pas la requête web');
        self::assertSame('/app/bin/restore-db.php db-20261019030000.sql.gz --dir=/home/x/backups --confirm=ma_base --scratch=ma_base_restore', $this->waitFor($this->dir . '/args'));
    }

    #[Test]
    public function testNoScratchBaseMeansNoScratchArgument(): void
    {
        $php = $this->fakePhp("echo \"\$@\" > {$this->dir}/args\n");

        (new RestoreLauncher($php, '/app/bin/restore-db.php', '/b', 'ma_base', null, $this->dir . '/restore.log', $this->dir))->launch('db-20261019030000.sql.gz');

        self::assertStringNotContainsString('--scratch', $this->waitFor($this->dir . '/args'));
    }

    #[Test]
    public function testAFileNameThatIsNotABackupNameIsNeverPassedOn(): void
    {
        $php = $this->fakePhp("touch {$this->dir}/launched\n");
        $launcher = new RestoreLauncher($php, '/app/bin/restore-db.php', '/b', 'ma_base', null, $this->dir . '/restore.log', $this->dir);

        foreach (['../x.sql.gz', '--confirm=x', 'db-1.sql.gz; rm -rf /', ''] as $name) {
            try {
                $launcher->launch($name);
                self::fail('Refus attendu');
            } catch (BackupException) {
            }
        }

        usleep(200_000);
        self::assertFileDoesNotExist($this->dir . '/launched');
    }

    #[Test]
    public function testTheLogIsPrivate(): void
    {
        $php = $this->fakePhp("echo bonjour\n");

        (new RestoreLauncher($php, '/app/bin/restore-db.php', '/b', 'ma_base', null, $this->dir . '/restore.log', $this->dir))->launch('db-20261019030000.sql.gz');

        self::assertSame('bonjour', $this->waitFor($this->dir . '/restore.log'));
        self::assertSame('600', substr(sprintf('%o', fileperms($this->dir . '/restore.log')), -3));
    }
}
