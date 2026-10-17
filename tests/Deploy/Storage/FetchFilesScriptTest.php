<?php

declare(strict_types=1);

namespace App\Tests\Deploy\Storage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #362 : copie hors serveur des fichiers de production (relevés de compte, documents de groupes), jouée pour de vrai avec un faux `ssh` qui
 * exécute la commande distante dans un faux dossier personnel : archive vérifiée et rangée en 0600, jamais dans le dépôt, noms en liste fermée,
 * jamais d'archive à moitié écrite.
 */
final class FetchFilesScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rb-fetchfiles-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/bin', 0o700, true);
        mkdir($this->root . '/home/rehearsalbox/shared/storage/accounting-statements', 0o700, true);
        file_put_contents($this->root . '/home/rehearsalbox/shared/storage/accounting-statements/aaaa', 'releve');
        file_put_contents($this->root . '/bin/ssh', "#!/bin/bash\nwhile [ \"\$1\" != \"rehearsalbox\" ]; do shift; done\nshift\nHOME=\"{$this->root}/home\" exec bash -c \"\$1\"\n");
        chmod($this->root . '/bin/ssh', 0o700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array{0: int, 1: string} */
    private function fetch(string $args = '', string $keep = '30', ?string $dir = null): array
    {
        $dir ??= $this->root . '/out';
        $command = sprintf(
            'PATH=%s:$PATH RB_SSH_CONFIG=/dev/null RB_BACKUP_LOCAL_DIR=%s RB_BACKUP_LOCAL_KEEP=%s bash %s %s 2>&1',
            escapeshellarg($this->root . '/bin'),
            escapeshellarg($dir),
            escapeshellarg($keep),
            escapeshellarg(__DIR__ . '/../../../bin/fetch-files.sh'),
            $args,
        );
        exec($command, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /** @return list<string> */
    private function archives(): array
    {
        return glob($this->root . '/out/*.tgz') ?: [];
    }

    #[Test]
    public function testTheArchiveIsStoredPrivatelyAndContainsTheFiles(): void
    {
        [$code, $output] = $this->fetch();

        self::assertSame(0, $code, $output);
        $archives = $this->archives();
        self::assertCount(1, $archives);
        self::assertSame('0600', substr(sprintf('%o', fileperms($archives[0])), -4));
        self::assertSame('0700', substr(sprintf('%o', fileperms($this->root . '/out')), -4));
        self::assertStringContainsString('accounting-statements/aaaa', (string) shell_exec('tar -tzf ' . escapeshellarg($archives[0])));
        self::assertSame([], glob($this->root . '/out/*.partial') ?: []);
    }

    #[Test]
    public function testOnlyTheTwoKnownFoldersAreAccepted(): void
    {
        foreach (['../x', "'; rm -rf /'", 'config', 'accounting-statements/..', ''] as $bad) {
            if ($bad === '') {
                continue;
            }
            [$code] = $this->fetch(escapeshellarg($bad));
            self::assertSame(1, $code, $bad);
        }
        self::assertSame([], $this->archives());
        [$code] = $this->fetch('group-documents');
        self::assertSame(0, $code, 'dossier absent du serveur : rien à copier, pas une erreur');
        self::assertSame([], $this->archives());
    }

    #[Test]
    public function testALocalFolderInsideTheRepositoryIsRefused(): void
    {
        $inside = realpath(__DIR__ . '/../../..') . '/backups-interdits';

        [$code, $output] = $this->fetch('', '30', $inside);

        self::assertSame(1, $code);
        self::assertStringContainsString('Refusé', $output);
        self::assertDirectoryDoesNotExist($inside);
    }

    #[Test]
    public function testOnlyTheMostRecentArchivesAreKept(): void
    {
        mkdir($this->root . '/out', 0o700);
        foreach (['20250101000000', '20250102000000', '20250103000000'] as $stamp) {
            exec('tar -C ' . escapeshellarg($this->root . '/home/rehearsalbox/shared/storage') . ' -czf ' . escapeshellarg($this->root . "/out/accounting-statements-{$stamp}.tgz") . ' accounting-statements');
        }

        [$code, $output] = $this->fetch('', '2');

        self::assertSame(0, $code, $output);
        self::assertCount(2, $this->archives());
        self::assertFileDoesNotExist($this->root . '/out/accounting-statements-20250101000000.tgz');
    }

    #[Test]
    public function testAFailingConnectionLeavesNothingBehind(): void
    {
        file_put_contents($this->root . '/bin/ssh', "#!/bin/bash\nexit 255\n");

        [$code] = $this->fetch();

        self::assertNotSame(0, $code);
        self::assertSame([], $this->archives());
        self::assertSame([], glob($this->root . '/out/*.partial') ?: []);
    }

    #[Test]
    public function testTheScriptNeverTouchesTheDatabaseOrPrintsSecrets(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../../bin/fetch-files.sh');

        self::assertStringContainsString('set -euo pipefail', $script);
        self::assertStringContainsString('umask 077', $script);
        self::assertFalse(stripos($script, 'mysql'));
        self::assertFalse(stripos($script, 'mariadb'));
        self::assertFalse(stripos($script, 'password'));
    }
}
