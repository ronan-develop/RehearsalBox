<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupException;
use App\Backup\ProcessImporter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #241 : l'import lance `mariadb` SANS jamais exposer le mot de passe, et ne relaie jamais ses messages. */
final class ProcessImporterTest extends TestCase
{
    private const DB = ['host' => 'localhost', 'port' => '3306', 'name' => 'ma_base', 'user' => 'mon_user', 'password' => 'p"a\\ss;$(touch /tmp/pwned)'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/process-importer-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** Faux `mariadb` : note arguments, fichier d'options et entrée standard reçus. */
    private function fakeBinary(string $body): string
    {
        $path = $this->dir . '/fake-mariadb.sh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0o755);

        return $path;
    }

    private function dump(string $sql = "CREATE TABLE t (id int);\n"): string
    {
        $path = $this->dir . '/dump.sql.gz';
        file_put_contents($path, gzencode($sql));

        return $path;
    }

    #[Test]
    public function testItStreamsTheDecompressedDumpToTheClientForTheRequestedDatabase(): void
    {
        $binary = $this->fakeBinary(<<<SH
            for a in "\$@"; do case "\$a" in --defaults-extra-file=*) f="\${a#--defaults-extra-file=}";; esac; done
            echo "\$f" > {$this->dir}/options-path
            stat -c %a "\$f" > {$this->dir}/options-mode
            echo "\$@" > {$this->dir}/arguments
            cat > {$this->dir}/stdin
            SH);

        (new ProcessImporter(self::DB, $binary))($this->dump("CREATE TABLE t (id int);\n-- é\n"), 'autre_base');

        self::assertSame("CREATE TABLE t (id int);\n-- é\n", file_get_contents($this->dir . '/stdin'));
        self::assertStringEndsWith('autre_base', trim((string) file_get_contents($this->dir . '/arguments')));
        self::assertStringNotContainsString('mon_user', (string) file_get_contents($this->dir . '/arguments'));
        self::assertStringNotContainsString('pwned', (string) file_get_contents($this->dir . '/arguments'));
        self::assertSame('600', trim((string) file_get_contents($this->dir . '/options-mode')));
        self::assertFileDoesNotExist(trim((string) file_get_contents($this->dir . '/options-path')), 'supprimé après usage');
    }

    #[Test]
    public function testAFailingImportRaisesAnErrorWithoutTheProgramMessagesNorTheSecret(): void
    {
        $binary = $this->fakeBinary("echo \"ERROR 1045 Access denied for user 'mon_user'\" >&2\ncat > /dev/null\nexit 3\n");

        try {
            (new ProcessImporter(self::DB, $binary))($this->dump(), 'ma_base');
            self::fail('Échec attendu');
        } catch (BackupException $e) {
            self::assertStringContainsString('code 3', $e->getMessage());
            self::assertStringNotContainsString('mon_user', $e->getMessage());
            self::assertStringNotContainsString('Access denied', $e->getMessage());
        }
    }

    #[Test]
    public function testTheOptionsFileIsRemovedEvenWhenTheClientDiesBeforeReadingTheDump(): void
    {
        $binary = $this->fakeBinary("for a in \"\$@\"; do case \"\$a\" in --defaults-extra-file=*) echo \"\${a#--defaults-extra-file=}\" > {$this->dir}/options-path;; esac; done\nexit 1\n");

        try {
            (new ProcessImporter(self::DB, $binary))($this->dump(str_repeat("INSERT INTO t VALUES (1);\n", 200_000)), 'ma_base');
            self::fail('Échec attendu');
        } catch (BackupException) {
        }

        self::assertFileDoesNotExist(trim((string) file_get_contents($this->dir . '/options-path')));
    }

    #[Test]
    public function testAnUnreadableOrNonGzipDumpIsRefusedBeforeLaunchingAnything(): void
    {
        $binary = $this->fakeBinary("touch {$this->dir}/launched\n");
        file_put_contents($this->dir . '/plain.sql.gz', "CREATE TABLE t (id int);\n");

        foreach ([$this->dir . '/plain.sql.gz', $this->dir . '/absent.sql.gz'] as $dump) {
            try {
                (new ProcessImporter(self::DB, $binary))($dump, 'ma_base');
                self::fail('Échec attendu');
            } catch (BackupException) {
            }
        }

        self::assertFileDoesNotExist($this->dir . '/launched');
    }

    #[Test]
    public function testADatabaseNameThatIsNotAPlainIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProcessImporter(self::DB, $this->dir . '/x'))($this->dump(), 'base; DROP');
    }

    #[Test]
    public function testAMissingProgramIsReportedWithoutTheCredentials(): void
    {
        try {
            (new ProcessImporter(self::DB, $this->dir . '/inexistant'))($this->dump(), 'ma_base');
            self::fail('Échec attendu');
        } catch (BackupException $e) {
            self::assertStringNotContainsString('mon_user', $e->getMessage());
        }
    }
}
