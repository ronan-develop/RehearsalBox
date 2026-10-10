<?php

declare(strict_types=1);

namespace App\Tests\Container;

use App\Messaging\Notification\ConversationNotifier;
use App\Messaging\Notification\ConversationReminderService;
use App\Messaging\Service\ConversationService;
use App\Messaging\Notification\MentionNotifier;
use App\Messaging\Notification\MentionReminderService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Le conteneur sait construire les services d'e-mail de la messagerie (le script cron s'appuie dessus). */
final class ReminderWiringTest extends TestCase
{
    #[Test]
    public function testTheContainerBuildsTheNotifierAndTheReminderService(): void
    {
        $config = require __DIR__ . '/../../config/config.php';
        // Base de test (comme IdorMatrixTest) : la configuration par défaut dépend du poste et échoue en CI.
        $config['db'] = [
            'host' => getenv('DB_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_TEST_PORT') ?: '3307',
            'name' => getenv('DB_TEST_NAME') ?: 'rehearsalbox_test',
            'user' => getenv('DB_TEST_USER') ?: 'root',
            'password' => getenv('DB_TEST_PASSWORD') ?: 'root',
        ];
        $config['messages']['key_file'] = \App\Tests\Support\TestMessageCipher::keyFile();
        $container = (require __DIR__ . '/../../config/services.php')($config);

        self::assertInstanceOf(ConversationNotifier::class, $container->get(ConversationNotifier::class));
        self::assertInstanceOf(ConversationReminderService::class, $container->get(ConversationReminderService::class));
        self::assertInstanceOf(MentionNotifier::class, $container->get(MentionNotifier::class));
        self::assertInstanceOf(MentionReminderService::class, $container->get(MentionReminderService::class));
        self::assertInstanceOf(ConversationService::class, $container->get(ConversationService::class), 'le service de messagerie reçoit le notificateur de mention');
    }

    #[Test]
    public function testTheScriptIsCliOnlyAndSyntacticallyValid(): void
    {
        $script = __DIR__ . '/../../bin/send-reminders.php';

        self::assertSame(0, $this->exitCodeOf('php -l ' . escapeshellarg($script)));
        $source = (string) file_get_contents($script);
        self::assertStringContainsString("PHP_SAPI !== 'cli'", $source, 'jamais exécutable depuis le web');
        self::assertStringContainsString('MentionReminderService', $source, 'le même cron envoie aussi les relances de mention');
    }

    private function exitCodeOf(string $command): int
    {
        exec($command . ' 2>&1', $output, $code);

        return $code;
    }
}
