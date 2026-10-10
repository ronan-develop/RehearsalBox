<?php

declare(strict_types=1);

namespace App\Tests\Container;

use App\Messaging\Crypto\MessageCipher;
use App\Messaging\Crypto\MessageCipherException;
use App\Messaging\Repository\ConversationMessageRepositoryInterface;
use App\Tests\Support\TestMessageCipher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #171 : le conteneur lit les clés dans le fichier configuré ; sans fichier valide, la messagerie ne lit ni n'écrit rien (jamais de clair en silence). */
final class MessageCipherWiringTest extends TestCase
{
    /** @param array<string, mixed> $messages */
    private function container(array $messages): object
    {
        $config = require __DIR__ . '/../../config/config.php';
        $config['db'] = [
            'host' => getenv('DB_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_TEST_PORT') ?: '3307',
            'name' => getenv('DB_TEST_NAME') ?: 'rehearsalbox_test',
            'user' => getenv('DB_TEST_USER') ?: 'root',
            'password' => getenv('DB_TEST_PASSWORD') ?: 'root',
        ];
        $config['messages'] = $messages;

        return (require __DIR__ . '/../../config/services.php')($config);
    }

    #[Test]
    public function testTheCipherComesFromTheConfiguredKeyFileAndIsSharedByTheRepositories(): void
    {
        $container = $this->container(['key_file' => TestMessageCipher::keyFile()]);

        $cipher = $container->get(MessageCipher::class);

        self::assertSame('Bonjour', $cipher->decrypt(TestMessageCipher::make()->encrypt('Bonjour')), 'même clé que le fichier');
        self::assertInstanceOf(ConversationMessageRepositoryInterface::class, $container->get(ConversationMessageRepositoryInterface::class));
    }

    #[Test]
    public function testWithoutAKeyFileNothingIsBuiltThatCouldWriteInClear(): void
    {
        $container = $this->container(['key_file' => sys_get_temp_dir() . '/rb-absent-' . bin2hex(random_bytes(4)) . '.json']);

        foreach ([MessageCipher::class, ConversationMessageRepositoryInterface::class] as $service) {
            try {
                $container->get($service);
                self::fail("Refus attendu pour {$service}");
            } catch (MessageCipherException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testTheDefaultKeyFileIsOutsideThePublicFolder(): void
    {
        $config = require __DIR__ . '/../../config/config.php';

        self::assertStringNotContainsString('/public/', $config['messages']['key_file']);
        self::assertStringEndsWith('config/message-keys.json', $config['messages']['key_file']);
    }
}
