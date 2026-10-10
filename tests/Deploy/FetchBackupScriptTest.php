<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #167 : la copie hors serveur du dernier dump de production se fait depuis le poste de dev, sans jamais ouvrir la base
 * ni afficher le contenu du dump ; le dossier local ne doit pas être dans le dépôt ; les messages restent chiffrés, donc
 * le rappel sur la clé doit toujours être affiché.
 */
final class FetchBackupScriptTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../bin/fetch-backup.sh');
    }

    #[Test]
    public function testScriptUsesStrictMode(): void
    {
        self::assertStringContainsString('set -euo pipefail', $this->script());
    }

    #[Test]
    public function testScriptSetsRestrictiveUmask(): void
    {
        self::assertStringContainsString('umask 077', $this->script());
    }

    #[Test]
    public function testScriptChecksGzipIntegrity(): void
    {
        self::assertStringContainsString('gzip -t', $this->script());
    }

    #[Test]
    public function testScriptDownloadsToPartialThenRenames(): void
    {
        $script = $this->script();

        self::assertStringContainsString('PARTIAL="$DEST.partial"', $script, 'téléchargement dans un fichier .partial');
        self::assertStringContainsString('mv "$PARTIAL" "$DEST"', $script, 'renommage seulement après vérification');
        self::assertStringContainsString('rm -f "$PARTIAL"', $script, 'le .partial est supprimé en cas d\'échec');
    }

    #[Test]
    public function testScriptRefusesLocalDirInsideRepository(): void
    {
        $script = $this->script();

        self::assertStringContainsString('"$REPO/"*)', $script, 'le dossier local résolu est comparé au dépôt');
        self::assertStringContainsString('Refusé', $script);
    }

    #[Test]
    public function testScriptNeverOpensTheDatabaseNorPrintsSecrets(): void
    {
        $script = $this->script();

        self::assertFalse(stripos($script, 'mysql'), 'aucun client de base dans le script');
        self::assertFalse(stripos($script, 'mariadb'), 'aucun client de base dans le script');
        self::assertFalse(stripos($script, 'password'), 'aucun mot de passe dans le script');
    }

    #[Test]
    public function testScriptEndsWithTheKeyReminder(): void
    {
        $script = $this->script();

        self::assertStringContainsString(
            'Les messages sont chiffrés : un dump seul ne suffit pas à les relire, conservez aussi la sauvegarde de la clé (KeePass), à part.',
            $script
        );
        self::assertStringEndsWith("\n", $script);
        self::assertStringEndsWith('à part."' . "\n", $script, 'rappel en dernière ligne');
    }

    #[Test]
    public function testSshConfigIsRequired(): void
    {
        self::assertStringContainsString('RB_SSH_CONFIG:?', $this->script());
    }

    #[Test]
    public function testScriptPassesBashSyntaxCheck(): void
    {
        $path = __DIR__ . '/../../bin/fetch-backup.sh';

        exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
    }
}
