<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #171 : les messages restent lisibles après un déploiement. Le fichier de clés vit dans shared/ (comme config.local.php), relié à
 * chaque release, jamais régénéré ; une release dont les clés ne lisent pas la base n'est PAS basculée ; et le déploiement ne
 * chiffre ni n'affiche rien tout seul (le rattrapage se fait après la sauvegarde de la clé par le propriétaire).
 */
final class MessageKeysDeployTest extends TestCase
{
    private function script(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../bin/' . $name);
    }

    #[Test]
    public function testEachReleaseIsLinkedToTheSharedKeyFile(): void
    {
        self::assertStringContainsString('ln -s "$HOME/$base/shared/message-keys.json" config/message-keys.json', $this->script('deploy.sh'));
    }

    #[Test]
    public function testTheKeyFileIsCreatedOnlyWhenAbsentAndOnTheServer(): void
    {
        $deploy = $this->script('deploy.sh');

        self::assertMatchesRegularExpression('/if \[ ! -e "\$keys" \]; then\s+"\$php" bin\/message-keys\.php init/', $deploy);
        self::assertStringContainsString('shared/message-keys.json', $deploy);
        self::assertStringNotContainsString('message-keys.php import', $deploy);
    }

    #[Test]
    public function testTheKeysMustReadTheDatabaseBeforeTheReleaseGoesLive(): void
    {
        $deploy = $this->script('deploy.sh');

        $check = strpos($deploy, 'bin/message-keys.php check');
        $switch = strpos($deploy, 'progress_step "Bascule vers');
        self::assertNotFalse($check);
        self::assertNotFalse($switch);
        self::assertLessThan($switch, $check, 'le contrôle de lecture précède la bascule');
        self::assertGreaterThan((int) strpos($deploy, 'bin/migrate.php'), $check, 'après les migrations, sur la base réelle');
    }

    #[Test]
    public function testTheDeployNeitherEncryptsTheDataNorPrintsAKey(): void
    {
        $deploy = $this->script('deploy.sh');

        self::assertStringNotContainsString('encrypt-messages.php', $deploy, 'le rattrapage est lancé à la main, après sauvegarde de la clé');
        self::assertStringNotContainsString('message-keys.php export', $deploy, 'jamais la clé dans la sortie du déploiement');
        self::assertStringNotContainsString('message-keys.php rotate', $deploy);
    }

    #[Test]
    public function testTheProgressBarCountsEveryStep(): void
    {
        $deploy = $this->script('deploy.sh');
        preg_match('/progress_init (\d+)/', $deploy, $total);

        // Une étape sautée (progress_skip) est l'autre branche d'un progress_step : elle ne compte pas en plus.
        self::assertSame((int) $total[1], preg_match_all('/^\s*progress_step /m', $deploy));
    }

    #[Test]
    public function testRollbackRefusesAReleaseThatCannotReadEncryptedMessages(): void
    {
        $rollback = $this->script('rollback.sh');

        self::assertStringContainsString('shared/message-keys.json', $rollback);
        self::assertStringContainsString('bin/message-keys.php', $rollback);
        self::assertStringContainsString('RB_FORCE_ROLLBACK', $rollback, 'contournement explicite et conscient');
    }

    #[Test]
    public function testTheKeyFileIsNeverShippedWithTheCode(): void
    {
        $gitignore = (string) file_get_contents(__DIR__ . '/../../.gitignore');

        self::assertStringContainsString('/config/message-keys.json', $gitignore);
    }
}
