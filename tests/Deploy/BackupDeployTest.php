<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #167 : le déploiement et le dump quotidien partagent UN script de sauvegarde, jamais un second bloc de dump à maintenir. */
final class BackupDeployTest extends TestCase
{
    private function script(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../bin/' . $name);
    }

    #[Test]
    public function testTheDeployUsesTheSharedBackupScriptBeforeTheMigrations(): void
    {
        $deploy = $this->script('deploy.sh');

        self::assertStringContainsString('bin/backup-db.php --kind=pre-deploy --label=', $deploy);
        self::assertStringContainsString('--skip-if-empty', $deploy, 'première installation : base vide, rien à sauvegarder');
        self::assertLessThan((int) strpos($deploy, 'bin/migrate.php'), (int) strpos($deploy, 'bin/backup-db.php'), 'la sauvegarde précède les migrations');
    }

    #[Test]
    public function testTheDeployNoLongerCarriesItsOwnDumpCode(): void
    {
        $deploy = $this->script('deploy.sh');

        self::assertStringNotContainsString('mariadb-dump', $deploy);
        self::assertStringNotContainsString('mysqldump', $deploy);
        self::assertStringNotContainsString('defaults-extra-file', $deploy, 'plus de gestion d\'identifiants dans le script de déploiement');
        self::assertStringNotContainsString('KEEP_BACKUPS', $deploy, 'la rotation vit dans le script PHP (BackupRetention)');
    }

    #[Test]
    public function testTheRollbackStillPointsToTheBeforeDeployDump(): void
    {
        self::assertStringContainsString('backups/pre-$current.sql.gz', $this->script('rollback.sh'));
    }

    #[Test]
    public function testTheBackupScriptNeverTakesCredentialsOnItsCommandLine(): void
    {
        $script = $this->script('backup-db.php');

        self::assertStringContainsString("getopt('', ['kind:', 'dir:', 'label:', 'skip-if-empty'])", $script, 'seuls ces arguments existent');
        self::assertStringNotContainsString("'password", $script);
        self::assertStringNotContainsString('--password', $script);
    }

    #[Test]
    public function testTheBackupFolderOfDailyDumpsIsTheOneOfTheDeploy(): void
    {
        self::assertStringContainsString('--dir=\\"\\$HOME/$BASE/backups\\"', $this->script('deploy.sh'));
    }
}
