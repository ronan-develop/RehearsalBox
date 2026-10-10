<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Crypto;

use App\Messaging\Crypto\MessageCipher;
use App\Messaging\Crypto\MessageCipherException;
use App\Messaging\Crypto\MessageReencryptor;
use App\Messaging\Crypto\SodiumMessageCipher;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** #171 : rattrapage des messages et titres écrits avant le chiffrement, et réécriture après une rotation de clé. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MessageReencryptorTest extends RepositoryTestCase
{
    use MessagingScenario;

    private function key(string $seed): string
    {
        return hash('sha256', $seed, true);
    }

    private function tolerant(): SodiumMessageCipher
    {
        return new SodiumMessageCipher(['k1' => $this->key('un')], 'k1', true);
    }

    /** @return array{int, int} identifiants de la conversation et du message écrits EN CLAIR, comme avant le chiffrement */
    private function legacy(string $title = 'Plan du concert', string $body = 'On joue samedi'): array
    {
        [$alice, , $a, $b] = $this->pair();
        $this->pdo->prepare('INSERT INTO conversations (initiator_group_id, target_group_id, created_by, title, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$a->id(), $b->id(), $alice->id(), $title, '2026-10-04 12:00:00']);
        $conversationId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO conversation_messages (conversation_id, author_id, body, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$conversationId, $alice->id(), $body, '2026-10-04 12:00:00']);
        $messageId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO conversation_message_versions (message_id, body, saved_at) VALUES (?, ?, ?)')
            ->execute([$messageId, 'Ancien texte', '2026-10-04 12:05:00']);

        return [$conversationId, $messageId];
    }

    private function raw(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }

    #[Test]
    public function testEncryptsEveryClearValueOfTheThreeTargetsAndLeavesNoPlaintextInTheDatabase(): void
    {
        $this->setUpScenario();
        [$conversationId, $messageId] = $this->legacy();
        $cipher = $this->tolerant();

        $report = (new MessageReencryptor($this->pdo, $cipher))->run();

        self::assertSame(['conversation_messages.body' => 1, 'conversation_message_versions.body' => 1, 'conversations.title' => 1], $report);
        $dump = $this->raw("SELECT CONCAT(c.title, '|', m.body, '|', v.body) FROM conversations c JOIN conversation_messages m ON m.conversation_id = c.id JOIN conversation_message_versions v ON v.message_id = m.id");
        foreach (['Plan du concert', 'On joue samedi', 'Ancien texte'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        self::assertSame('On joue samedi', $cipher->decrypt($this->raw("SELECT body FROM conversation_messages WHERE id = {$messageId}")));
        self::assertSame('Plan du concert', $cipher->decrypt($this->raw("SELECT title FROM conversations WHERE id = {$conversationId}")));
    }

    #[Test]
    public function testASecondRunRewritesNothing(): void
    {
        $this->setUpScenario();
        $this->legacy();
        $reencryptor = new MessageReencryptor($this->pdo, $this->tolerant());
        $reencryptor->run();
        $before = $this->raw('SELECT body FROM conversation_messages LIMIT 1');

        $report = $reencryptor->run();

        self::assertSame(['conversation_messages.body' => 0, 'conversation_message_versions.body' => 0, 'conversations.title' => 0], $report);
        self::assertSame($before, $this->raw('SELECT body FROM conversation_messages LIMIT 1'), 'la valeur chiffrée n\'est pas re-chiffrée');
    }

    #[Test]
    public function testAConversationWithoutTitleIsLeftAlone(): void
    {
        $this->setUpScenario();
        [$alice, , $a, $b] = $this->pair();
        $this->pdo->prepare('INSERT INTO conversations (initiator_group_id, target_group_id, created_by, title, created_at) VALUES (?, ?, ?, NULL, ?)')
            ->execute([$a->id(), $b->id(), $alice->id(), '2026-10-04 12:00:00']);

        $report = (new MessageReencryptor($this->pdo, $this->tolerant()))->run();

        self::assertSame(0, $report['conversations.title']);
        self::assertNull($this->pdo->query('SELECT title FROM conversations')->fetchColumn() ?: null);
    }

    #[Test]
    public function testAKeyRotationRewritesTheValuesOfTheFormerKeyAndOnlyThose(): void
    {
        $this->setUpScenario();
        $this->legacy();
        (new MessageReencryptor($this->pdo, $this->tolerant()))->run();
        $rotated = new SodiumMessageCipher(['k1' => $this->key('un'), 'k2' => $this->key('deux')], 'k2');

        $first = (new MessageReencryptor($this->pdo, $rotated))->run();
        $second = (new MessageReencryptor($this->pdo, $rotated))->run();

        self::assertSame(['conversation_messages.body' => 1, 'conversation_message_versions.body' => 1, 'conversations.title' => 1], $first);
        self::assertSame(0, array_sum($second));
        self::assertStringStartsWith('v1.k2:', $this->raw('SELECT body FROM conversation_messages LIMIT 1'));
        $onlyNew = new SodiumMessageCipher(['k2' => $this->key('deux')], 'k2');
        self::assertSame('On joue samedi', $onlyNew->decrypt($this->raw('SELECT body FROM conversation_messages LIMIT 1')), 'la clé d\'avant n\'est plus nécessaire pour ces valeurs');
    }

    #[Test]
    public function testItWorksAcrossSeveralBatches(): void
    {
        $this->setUpScenario();
        [$conversationId] = $this->legacy();
        $author = (int) $this->raw('SELECT author_id FROM conversation_messages LIMIT 1');
        for ($i = 0; $i < 450; ++$i) {
            $this->pdo->prepare('INSERT INTO conversation_messages (conversation_id, author_id, body, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$conversationId, $author, "Message {$i}", '2026-10-04 12:00:00']);
        }

        $report = (new MessageReencryptor($this->pdo, $this->tolerant()))->run();

        self::assertSame(451, $report['conversation_messages.body']);
        self::assertSame(0, (new MessageReencryptor($this->pdo, $this->tolerant()))->countPlaintext());
    }

    #[Test]
    public function testCountPlaintextSeesWhatIsStillInClear(): void
    {
        $this->setUpScenario();
        $this->legacy();
        $reencryptor = new MessageReencryptor($this->pdo, $this->tolerant());

        self::assertSame(3, $reencryptor->countPlaintext());

        $reencryptor->run();
        self::assertSame(0, $reencryptor->countPlaintext());
    }

    #[Test]
    public function testPendingCountsWhatARunWouldRewriteWithoutWritingAnything(): void
    {
        $this->setUpScenario();
        $this->legacy();
        $reencryptor = new MessageReencryptor($this->pdo, $this->tolerant());
        $before = $this->raw('SELECT body FROM conversation_messages LIMIT 1');

        self::assertSame(3, $reencryptor->pending());
        self::assertSame($before, $this->raw('SELECT body FROM conversation_messages LIMIT 1'), 'lecture seule');

        $reencryptor->run();
        self::assertSame(0, $reencryptor->pending());
    }

    #[Test]
    public function testVerifyReadableDecryptsTheNewestEncryptedValuesAndCountsThem(): void
    {
        $this->setUpScenario();
        $this->legacy();
        $reencryptor = new MessageReencryptor($this->pdo, $this->tolerant());
        self::assertSame(0, $reencryptor->verifyReadable(), 'rien de chiffré : rien à vérifier (le clair est ignoré)');

        $reencryptor->run();

        self::assertSame(3, $reencryptor->verifyReadable());
        self::assertSame(3, $reencryptor->verifyReadable(1), "un échantillon d\x27une valeur par colonne");
    }

    #[Test]
    public function testVerifyReadableFailsWhenTheKeyCannotReadWhatIsStoredWithoutNamingTheText(): void
    {
        $this->setUpScenario();
        [, $messageId] = $this->legacy();
        (new MessageReencryptor($this->pdo, $this->tolerant()))->run();
        $otherKey = new SodiumMessageCipher(['k1' => $this->key('perdue')], 'k1', true);

        try {
            (new MessageReencryptor($this->pdo, $otherKey))->verifyReadable();
            self::fail('Échec attendu : mauvaise clé');
        } catch (MessageCipherException $e) {
            self::assertStringContainsString((string) $messageId, $e->getMessage() . ' #' . $messageId);
            self::assertStringNotContainsString('On joue samedi', $e->getMessage());
            self::assertStringContainsString('conversation', $e->getMessage());
        }
    }

    #[Test]
    public function testAClearValueIsRefusedOnceTheTransitionIsOver(): void
    {
        $this->setUpScenario();
        $this->legacy();
        $strict = new SodiumMessageCipher(['k1' => $this->key('un')], 'k1', false);

        $this->expectException(MessageCipherException::class);
        (new MessageReencryptor($this->pdo, $strict))->run();
    }

    #[Test]
    public function testAValueChangedWhileItWasBeingEncryptedIsNotOverwritten(): void
    {
        $this->setUpScenario();
        [, $messageId] = $this->legacy();
        $inner = $this->tolerant();
        $pdo = $this->pdo;
        // Pendant le rattrapage, quelqu'un corrige le message (le nouveau code l'écrit chiffré) : le rattrapage ne doit pas l'écraser.
        $racing = new class ($inner, $pdo, $messageId) implements MessageCipher {
            private bool $done = false;

            public function __construct(private readonly MessageCipher $inner, private readonly \PDO $pdo, private readonly int $messageId)
            {
            }

            public function encrypt(string $plain): string
            {
                if (!$this->done && $plain === 'On joue samedi') {
                    $this->done = true;
                    $this->pdo->prepare('UPDATE conversation_messages SET body = ? WHERE id = ?')->execute([$this->inner->encrypt('Corrigé entre-temps'), $this->messageId]);
                }

                return $this->inner->encrypt($plain);
            }

            public function decrypt(string $stored): string
            {
                return $this->inner->decrypt($stored);
            }

            public function isEncrypted(string $stored): bool
            {
                return $this->inner->isEncrypted($stored);
            }

            public function isCurrent(string $stored): bool
            {
                return $this->inner->isCurrent($stored);
            }
        };

        (new MessageReencryptor($this->pdo, $racing))->run();

        self::assertSame('Corrigé entre-temps', $this->tolerant()->decrypt($this->raw("SELECT body FROM conversation_messages WHERE id = {$messageId}")));
    }
}
