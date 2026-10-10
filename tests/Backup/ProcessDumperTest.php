<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupException;
use App\Backup\ProcessDumper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #167 : le dump lance `mariadb-dump` SANS jamais exposer le mot de passe (ni en argument, ni dans une erreur). */
final class ProcessDumperTest extends TestCase
{
    private const DB = ['host' => 'localhost', 'port' => '3306', 'name' => 'ma_base', 'user' => 'mon_user', 'password' => 'p"a\\ss;$(touch /tmp/pwned)'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/process-dumper-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** Faux `mariadb-dump` : note ses arguments et le fichier d'options reçus, puis écrit un dump sur la sortie standard. */
    private function fakeBinary(string $body): string
    {
        $path = $this->dir . '/fake-dump.sh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0o755);

        return $path;
    }

    private function lines(string $gz): string
    {
        return (string) gzdecode((string) file_get_contents($gz));
    }

    #[Test]
    public function testTheOptionsFileCarriesTheCredentialsEscapedAndTheArgumentsNeverDo(): void
    {
        $dumper = new ProcessDumper(self::DB);

        $options = $dumper->optionsFile();
        $arguments = $dumper->arguments('/tmp/opts.cnf');

        self::assertStringContainsString("[client]\n", $options);
        self::assertStringContainsString("user=mon_user\n", $options);
        self::assertStringContainsString("default-character-set=utf8mb4\n", $options);
        self::assertStringContainsString('password="p\\"a\\\\ss;$(touch /tmp/pwned)"', $options, 'guillemets et barres inverses échappés');
        self::assertSame('--defaults-extra-file=/tmp/opts.cnf', $arguments[1], 'doit venir avant toute autre option');
        self::assertContains('--single-transaction', $arguments);
        self::assertContains('--routines', $arguments);
        self::assertContains('--no-tablespaces', $arguments);
        self::assertSame('ma_base', end($arguments));
        foreach ($arguments as $argument) {
            self::assertStringNotContainsString('pwned', $argument, 'jamais le mot de passe en argument');
            self::assertStringNotContainsString('mon_user', $argument);
        }
    }

    #[Test]
    public function testItWritesAGzipOfTheDumpAndHandsTheCredentialsThroughAPrivateTemporaryFile(): void
    {
        $binary = $this->fakeBinary(<<<SH
            for a in "\$@"; do case "\$a" in --defaults-extra-file=*) f="\${a#--defaults-extra-file=}";; esac; done
            echo "\$f" > {$this->dir}/options-path
            stat -c %a "\$f" > {$this->dir}/options-mode
            grep -c '^password=' "\$f" > {$this->dir}/options-has-password
            printf -- '-- fake dump\\nCREATE TABLE t (id int);\\n\\n-- Dump completed on 2026-10-11 03:30:00\\n'
            SH);
        $destination = $this->dir . '/out.sql.gz';

        (new ProcessDumper(self::DB, $binary))($destination);

        self::assertStringContainsString('CREATE TABLE t', $this->lines($destination));
        self::assertSame('600', trim((string) file_get_contents($this->dir . '/options-mode')), 'fichier d\'options en 0600');
        self::assertSame('1', trim((string) file_get_contents($this->dir . '/options-has-password')));
        self::assertFileDoesNotExist(trim((string) file_get_contents($this->dir . '/options-path')), 'supprimé après usage');
    }

    #[Test]
    public function testAFailingDumpRaisesAnErrorThatNeverCarriesTheProgramMessagesNorTheSecret(): void
    {
        $binary = $this->fakeBinary("echo \"Access denied for user 'mon_user'@'localhost' using password: YES\" >&2\nexit 2\n");

        try {
            (new ProcessDumper(self::DB, $binary))($this->dir . '/out.sql.gz');
            self::fail('Échec attendu');
        } catch (BackupException $e) {
            self::assertStringContainsString('code 2', $e->getMessage());
            self::assertStringNotContainsString('mon_user', $e->getMessage());
            self::assertStringNotContainsString('Access denied', $e->getMessage());
            self::assertStringNotContainsString('pwned', $e->getMessage());
        }
    }

    #[Test]
    public function testTheOptionsFileIsRemovedEvenWhenTheDumpFails(): void
    {
        $binary = $this->fakeBinary("for a in \"\$@\"; do case \"\$a\" in --defaults-extra-file=*) echo \"\${a#--defaults-extra-file=}\" > {$this->dir}/options-path;; esac; done\nexit 1\n");

        try {
            (new ProcessDumper(self::DB, $binary))($this->dir . '/out.sql.gz');
        } catch (BackupException) {
        }

        self::assertFileDoesNotExist(trim((string) file_get_contents($this->dir . '/options-path')));
    }

    #[Test]
    public function testAMissingProgramIsReportedWithoutTheCredentials(): void
    {
        try {
            (new ProcessDumper(self::DB, $this->dir . '/inexistant'))($this->dir . '/out.sql.gz');
            self::fail('Échec attendu');
        } catch (BackupException $e) {
            self::assertStringNotContainsString('mon_user', $e->getMessage());
            self::assertStringNotContainsString('pwned', $e->getMessage());
        }
    }

    #[Test]
    public function testIncompleteConfigurationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProcessDumper(['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => 'u', 'password' => 'p']);
    }
}
