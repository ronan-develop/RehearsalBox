<?php

declare(strict_types=1);

namespace App\Tests\Container;

use App\Service\ConversationNotifier;
use App\Service\ConversationReminderService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Le conteneur sait construire les services d'e-mail de la messagerie (le script cron s'appuie dessus). */
final class ReminderWiringTest extends TestCase
{
    #[Test]
    public function testTheContainerBuildsTheNotifierAndTheReminderService(): void
    {
        $config = require __DIR__ . '/../../config/config.php';
        $container = (require __DIR__ . '/../../config/services.php')($config);

        self::assertInstanceOf(ConversationNotifier::class, $container->get(ConversationNotifier::class));
        self::assertInstanceOf(ConversationReminderService::class, $container->get(ConversationReminderService::class));
    }

    #[Test]
    public function testTheScriptIsCliOnlyAndSyntacticallyValid(): void
    {
        $script = __DIR__ . '/../../bin/send-reminders.php';

        self::assertSame(0, $this->exitCodeOf('php -l ' . escapeshellarg($script)));
        self::assertStringContainsString("PHP_SAPI !== 'cli'", (string) file_get_contents($script), 'jamais exécutable depuis le web');
    }

    private function exitCodeOf(string $command): int
    {
        exec($command . ' 2>&1', $output, $code);

        return $code;
    }
}
