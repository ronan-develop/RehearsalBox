<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Crypto;

use App\Messaging\Crypto\MessageKeyFile;
use App\Messaging\Crypto\MessageKeysCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #171 : les commandes du propriétaire (`bin/message-keys.php`) : créer, vérifier, sauvegarder, restaurer, faire tourner la clé, finir la transition. */
final class MessageKeysCommandTest extends TestCase
{
    private string $dir;
    private MessageKeyFile $file;
    private int $plaintext = 0;
    private ?\Closure $readable = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/message-keys-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
        $this->file = new MessageKeyFile($this->dir . '/message-keys.json');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @return array{int, string} */
    private function command(string $command, string $input = ''): array
    {
        return (new MessageKeysCommand($this->file, fn (): int => $this->plaintext, $this->readable ?? fn (): int => 0))->run($command, $input);
    }

    #[Test]
    public function testInitCreatesTheFileAndTellsToBackItUpWithoutShowingTheKey(): void
    {
        [$code, $output] = $this->command('init');

        self::assertSame(0, $code);
        self::assertTrue($this->file->exists());
        self::assertStringContainsString('export', $output, 'rappelle de sauvegarder la clé');
        $key = json_decode((string) file_get_contents($this->dir . '/message-keys.json'), true)['keys']['k1'];
        self::assertStringNotContainsString($key, $output, 'la clé n\'est pas affichée');
    }

    #[Test]
    public function testInitRefusesToOverwriteAnExistingFile(): void
    {
        $this->command('init');
        $before = file_get_contents($this->dir . '/message-keys.json');

        [$code, $output] = $this->command('init');

        self::assertSame(1, $code);
        self::assertStringContainsString('existe déjà', $output);
        self::assertSame($before, file_get_contents($this->dir . '/message-keys.json'));
    }

    #[Test]
    public function testStatusNamesTheKeysAndTheTransitionButNeverShowsAKey(): void
    {
        $this->command('init');
        $this->command('rotate');
        $raw = (string) file_get_contents($this->dir . '/message-keys.json');
        preg_match('/"k1": "([^"]+)"/', $raw, $key);

        [$code, $output] = $this->command('status');

        self::assertSame(0, $code);
        self::assertStringContainsString('k2', $output);
        self::assertStringContainsString('transition', $output);
        self::assertStringNotContainsString($key[1], $output);
    }

    #[Test]
    public function testExportThenImportRestoresTheSameKeys(): void
    {
        $this->command('init');
        $stored = $this->file->cipher()->encrypt('Message');
        [, $backup] = $this->command('export');
        unlink($this->dir . '/message-keys.json');

        [$code] = $this->command('import', $backup);

        self::assertSame(0, $code);
        self::assertSame('Message', $this->file->cipher()->decrypt($stored));
    }

    #[Test]
    public function testRotateAddsAKeyAndRemindsToRewriteTheMessages(): void
    {
        $this->command('init');

        [$code, $output] = $this->command('rotate');

        self::assertSame(0, $code);
        self::assertStringContainsString('k2', $output);
        self::assertStringContainsString('encrypt-messages.php', $output);
    }

    #[Test]
    public function testStrictIsRefusedWhileMessagesAreStillInClear(): void
    {
        $this->command('init');
        $this->plaintext = 4;

        [$code, $output] = $this->command('strict');

        self::assertSame(1, $code);
        self::assertStringContainsString('4', $output);
        self::assertTrue($this->file->status()['allowPlaintext'], 'la transition reste ouverte');
    }

    #[Test]
    public function testStrictEndsTheTransitionOnceNothingIsLeftInClear(): void
    {
        $this->command('init');
        $this->plaintext = 0;

        [$code] = $this->command('strict');

        self::assertSame(0, $code);
        self::assertFalse($this->file->status()['allowPlaintext']);
    }

    #[Test]
    public function testCheckProvesTheKeysCanReadWhatIsStoredBeforeADeployGoesLive(): void
    {
        $this->command('init');
        $this->readable = fn (): int => 42;

        [$code, $output] = $this->command('check');

        self::assertSame(0, $code);
        self::assertStringContainsString('42', $output);
    }

    #[Test]
    public function testCheckFailsWhenTheKeysCannotReadTheStoredMessages(): void
    {
        $this->command('init');
        $this->readable = function (): int {
            throw new \App\Messaging\Crypto\MessageCipherException('Lecture impossible : conversation_messages #7.');
        };

        [$code, $output] = $this->command('check');

        self::assertSame(1, $code);
        self::assertStringContainsString('conversation_messages #7', $output);
    }

    #[Test]
    public function testCheckFailsWithoutAKeyFile(): void
    {
        [$code, $output] = $this->command('check');

        self::assertSame(1, $code);
        self::assertStringContainsString('absent', $output);
    }

    #[Test]
    public function testTransitionReopensPlaintextAndExplainsTheNextSteps(): void
    {
        $this->command('init');
        $this->command('strict');
        self::assertFalse($this->file->status()['allowPlaintext']);

        [$code, $output] = $this->command('transition');

        self::assertSame(0, $code);
        self::assertTrue($this->file->status()['allowPlaintext']);
        self::assertStringContainsString('encrypt-messages.php', $output);
        self::assertStringContainsString('strict', $output, 'rappelle de refermer la transition ensuite');
    }

    #[Test]
    public function testTransitionWithoutAKeyFileIsRefused(): void
    {
        [$code, $output] = $this->command('transition');

        self::assertSame(1, $code);
        self::assertStringContainsString('absent', $output);
    }

    #[Test]
    public function testAnUnknownCommandAndAMissingFileAreExplained(): void
    {
        [$code, $output] = $this->command('nimporte');
        self::assertSame(2, $code);
        self::assertStringContainsString('init', $output);
        self::assertStringContainsString('transition', $output, 'l\'aide cite la nouvelle commande');

        [$code, $output] = $this->command('status');
        self::assertSame(1, $code);
        self::assertStringContainsString('absent', $output);
    }
}
