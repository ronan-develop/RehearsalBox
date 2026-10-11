<?php

declare(strict_types=1);

namespace App\Tests\Bin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #440 : chaque déploiement referme les droits de shared/ et de backups/, puis les contrôle avant la bascule. */
final class ServerPermissionsDeployTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/rb-perms-' . bin2hex(random_bytes(4));
        mkdir($this->home . '/app/shared/storage/group-documents', 0o775, true);
        mkdir($this->home . '/app/shared/storage/logs', 0o775, true);
        mkdir($this->home . '/app/shared/well-known', 0o775, true);
        mkdir($this->home . '/app/backups', 0o755, true);
        foreach (['shared/config.local.php' => 0o664, 'shared/reminders.log' => 0o644, 'shared/storage/logs/app.log' => 0o664, 'shared/storage/group-documents/doc.pdf' => 0o644, 'backups/db.sql.gz' => 0o644] as $file => $mode) {
            file_put_contents($this->home . '/app/' . $file, 'x');
            chmod($this->home . '/app/' . $file, $mode);
        }
        chmod($this->home . '/app/shared', 0o775);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    /** Le corps du bloc distant « Droits des fichiers du serveur » de bin/deploy.sh, tel qu'il s'exécute sur le serveur. */
    private function remoteBlock(): string
    {
        $script = (string) file_get_contents(__DIR__ . '/../../bin/deploy.sh');
        self::assertSame(1, preg_match("/progress_step \"Droits des fichiers du serveur\"\nrb_ssh bash -s -- \"\\\$BASE\" <<'REMOTE'\n(.*?)\nREMOTE\n/s", $script, $match), 'bloc distant introuvable');

        return $match[1];
    }

    /** @return array{int, string} code de sortie et sortie d'erreur */
    private function runBlock(): array
    {
        $process = proc_open(['bash', '-s', '--', 'app'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['HOME' => $this->home, 'PATH' => (string) getenv('PATH')]);
        fwrite($pipes[0], $this->remoteBlock());
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $errors];
    }

    private function mode(string $relative): string
    {
        return substr(sprintf('%o', fileperms($this->home . '/app/' . $relative)), -3);
    }

    #[Test]
    public function testSecretsDocumentsLogsAndBackupsAreClosedToEveryoneButTheOwner(): void
    {
        [$code] = $this->runBlock();

        self::assertSame(0, $code);
        foreach (['shared' => '700', 'backups' => '700', 'shared/storage' => '700', 'shared/storage/group-documents' => '700', 'shared/config.local.php' => '600', 'shared/reminders.log' => '600', 'shared/storage/logs/app.log' => '600', 'shared/storage/group-documents/doc.pdf' => '600', 'backups/db.sql.gz' => '600'] as $path => $mode) {
            self::assertSame($mode, $this->mode($path), $path);
        }
    }

    #[Test]
    public function testOnlyTheCertificateChallengeFolderStaysReadableByTheWebServer(): void
    {
        $this->runBlock();

        self::assertSame('755', $this->mode('shared/well-known'));
    }

    #[Test]
    public function testLeftoverConfigurationCopiesAreReportedNotDeleted(): void
    {
        file_put_contents($this->home . '/app/shared/config.local.php.bak-20261011', 'secrets');

        [$code, $errors] = $this->runBlock();

        self::assertSame(0, $code, 'un avertissement, pas un échec');
        self::assertStringContainsString('config.local.php.bak-20261011', $errors);
        self::assertFileExists($this->home . '/app/shared/config.local.php.bak-20261011');
    }

    #[Test]
    public function testTheCheckStopsTheDeploymentWhenSomethingStaysOpen(): void
    {
        // Un fichier que le propriétaire ne peut pas refermer : lien vers un fichier d'un autre compte ou système de fichiers sans droits.
        $script = str_replace('find shared -maxdepth 1 -type f -exec chmod 600 {} +', 'true', $this->remoteBlock());
        $process = proc_open(['bash', '-s', '--', 'app'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['HOME' => $this->home, 'PATH' => (string) getenv('PATH')]);
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        self::assertSame(1, proc_close($process));
        self::assertStringContainsString('ÉCHEC', $errors);
        self::assertStringContainsString('config.local.php', $errors);
    }

    #[Test]
    public function testTheStepRunsBeforeTheReleaseIsSwitched(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../bin/deploy.sh');

        self::assertLessThan(strpos($script, 'ln -sfn "$HOME/$base/releases/$rel" current.new'), strpos($script, 'Droits des fichiers du serveur'));
    }
}
