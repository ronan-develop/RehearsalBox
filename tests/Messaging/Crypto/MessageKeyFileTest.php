<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Crypto;

use App\Messaging\Crypto\MessageCipherException;
use App\Messaging\Crypto\MessageKeyFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #171 : le fichier de clés vit sur le serveur, hors dépôt et hors base, créé une seule fois, lisible par le seul propriétaire. */
final class MessageKeyFileTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/message-keys-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
        $this->path = $this->dir . '/message-keys.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    #[Test]
    public function testInitCreatesAPrivateFileWithOneKeyAndTheTransitionOpen(): void
    {
        $file = new MessageKeyFile($this->path);

        $file->init();

        self::assertSame(0o600, fileperms($this->path) & 0o777);
        $cipher = $file->cipher();
        self::assertSame('Bonjour', $cipher->decrypt($cipher->encrypt('Bonjour')));
        self::assertSame('Texte en clair', $cipher->decrypt('Texte en clair'), 'transition : le clair d\'avant reste lisible');
        self::assertStringStartsWith('v1.k1:', $cipher->encrypt('x'));
    }

    #[Test]
    public function testInitNeverOverwritesAnExistingFile(): void
    {
        $file = new MessageKeyFile($this->path);
        $file->init();
        $before = file_get_contents($this->path);

        try {
            $file->init();
            self::fail('Refus attendu');
        } catch (\LogicException) {
            self::assertSame($before, file_get_contents($this->path), 'la clé existante est intacte');
        }
    }

    #[Test]
    public function testEveryFileHasADifferentKey(): void
    {
        (new MessageKeyFile($this->path))->init();
        $other = new MessageKeyFile($this->dir . '/autre.json');
        $other->init();

        $stored = (new MessageKeyFile($this->path))->cipher()->encrypt('Message');

        $this->expectException(MessageCipherException::class);
        $other->cipher()->decrypt($stored);
    }

    #[Test]
    public function testAMissingUnreadableOrOpenFileIsRefusedSoNothingIsWrittenInClear(): void
    {
        $missing = new MessageKeyFile($this->path);
        foreach ([
            'absent' => fn () => $missing->cipher(),
            'json invalide' => function () {
                file_put_contents($this->path, 'pas du json');
                chmod($this->path, 0o600);

                return (new MessageKeyFile($this->path))->cipher();
            },
            'clé trop courte' => function () {
                file_put_contents($this->path, json_encode(['current' => 'k1', 'allow_plaintext' => false, 'keys' => ['k1' => base64_encode('court')]]));

                return (new MessageKeyFile($this->path))->cipher();
            },
            'droits trop ouverts' => function () {
                (new MessageKeyFile($this->path))->init();
                chmod($this->path, 0o644);

                return (new MessageKeyFile($this->path))->cipher();
            },
        ] as $case => $load) {
            try {
                $load();
                self::fail("Refus attendu : {$case}");
            } catch (MessageCipherException $e) {
                self::addToAssertionCount(1);
                self::assertStringNotContainsString('court', $e->getMessage());
            }
            @unlink($this->path);
        }
    }

    #[Test]
    public function testEndingTheTransitionRefusesPlaintextAndKeepsEveryKey(): void
    {
        $file = new MessageKeyFile($this->path);
        $file->init();
        $stored = $file->cipher()->encrypt('Message');

        $file->endTransition();

        self::assertSame(0o600, fileperms($this->path) & 0o777);
        self::assertSame('Message', $file->cipher()->decrypt($stored));
        $this->expectException(MessageCipherException::class);
        $file->cipher()->decrypt('Texte en clair');
    }

    #[Test]
    public function testReopeningTheTransitionLetsPlaintextBeReadAgainAndKeepsEveryKey(): void
    {
        $file = new MessageKeyFile($this->path);
        $file->init();
        $file->rotate();
        $stored = $file->cipher()->encrypt('Message');
        $file->endTransition();
        try {
            $file->cipher()->decrypt('Texte en clair');
            self::fail('Refus attendu en mode strict');
        } catch (MessageCipherException) {
            self::addToAssertionCount(1);
        }

        $file->beginTransition();

        self::assertSame(0o600, fileperms($this->path) & 0o777);
        self::assertSame(['current' => 'k2', 'keys' => ['k1', 'k2'], 'allowPlaintext' => true], $file->status());
        self::assertSame('Texte en clair', $file->cipher()->decrypt('Texte en clair'), 'un dump d\'avant le chiffrement redevient lisible');
        self::assertSame('Message', $file->cipher()->decrypt($stored), 'le chiffré reste lisible');
    }

    #[Test]
    public function testReopeningTheTransitionNeedsAValidKeyFile(): void
    {
        $this->expectException(MessageCipherException::class);
        (new MessageKeyFile($this->path))->beginTransition();
    }

    #[Test]
    public function testRotatingAddsANewCurrentKeyAndOldMessagesStayReadable(): void
    {
        $file = new MessageKeyFile($this->path);
        $file->init();
        $old = $file->cipher()->encrypt('Ancien');

        self::assertSame('k2', $file->rotate());

        $cipher = $file->cipher();
        self::assertSame('Ancien', $cipher->decrypt($old));
        self::assertStringStartsWith('v1.k2:', $cipher->encrypt('Neuf'));
        self::assertFalse($cipher->isCurrent($old));
        self::assertSame('k3', $file->rotate());
    }

    #[Test]
    public function testStatusNamesTheKeysAndNeverShowsThem(): void
    {
        $file = new MessageKeyFile($this->path);
        $file->init();
        $file->rotate();

        $status = $file->status();

        self::assertSame(['current' => 'k2', 'keys' => ['k1', 'k2'], 'allowPlaintext' => true], $status);
    }

    #[Test]
    public function testTheExportIsAFileTheOwnerCanKeepAndRestore(): void
    {
        $file = new MessageKeyFile($this->path);
        $file->init();
        $stored = $file->cipher()->encrypt('Message');

        $backup = $file->export();
        $restored = new MessageKeyFile($this->dir . '/restaure.json');
        $restored->import($backup);

        self::assertSame('Message', $restored->cipher()->decrypt($stored));
        self::assertSame(0o600, fileperms($this->dir . '/restaure.json') & 0o777);
    }

    #[Test]
    public function testImportRefusesToOverwriteAndRejectsGarbage(): void
    {
        $file = new MessageKeyFile($this->path);
        $file->init();

        try {
            $file->import($file->export());
            self::fail('Refus attendu : fichier existant');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        (new MessageKeyFile($this->dir . '/autre.json'))->import('pas une sauvegarde');
    }

    // --- Lien vers shared/ (déploiement par releases) ------------------------------------------------

    #[Test]
    public function testThroughASymlinkTheKeysLiveInTheTargetAndTheLinkSurvivesEveryWrite(): void
    {
        mkdir($this->dir . '/shared', 0o700);
        mkdir($this->dir . '/release', 0o700);
        $target = $this->dir . '/shared/message-keys.json';
        $link = $this->dir . '/release/message-keys.json';
        symlink($target, $link); // lien pendant : le fichier partagé n'existe pas encore (premier déploiement)
        $file = new MessageKeyFile($link);

        self::assertFalse($file->exists());
        $file->init();
        self::assertTrue(is_link($link), 'le lien reste un lien');
        self::assertFileExists($target);
        $stored = $file->cipher()->encrypt('Message');

        $file->rotate();
        $file->endTransition();

        self::assertTrue(is_link($link));
        self::assertSame(['current' => 'k2', 'keys' => ['k1', 'k2'], 'allowPlaintext' => false], (new MessageKeyFile($target))->status());
        // Une nouvelle release relie le même fichier : les messages d'avant restent lisibles.
        mkdir($this->dir . '/release2', 0o700);
        symlink($target, $this->dir . '/release2/message-keys.json');
        self::assertSame('Message', (new MessageKeyFile($this->dir . '/release2/message-keys.json'))->cipher()->decrypt($stored));

        unlink($link);
        unlink($this->dir . '/release2/message-keys.json');
        unlink($target);
        rmdir($this->dir . '/shared');
        rmdir($this->dir . '/release');
        rmdir($this->dir . '/release2');
    }
}
