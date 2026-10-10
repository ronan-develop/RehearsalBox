<?php

declare(strict_types=1);

namespace App\Tests\Container;

use App\Backup\Controller\RestoreController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #241 : le conteneur construit la page de restauration, la route existe, et le script est réservé à la ligne de commande. */
final class RestoreWiringTest extends TestCase
{
    #[Test]
    public function testTheContainerBuildsTheRestoreControllerAndTheRoutesPointToIt(): void
    {
        $config = require __DIR__ . '/../../config/config.php';
        $config['db'] = [
            'host' => getenv('DB_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_TEST_PORT') ?: '3307',
            'name' => getenv('DB_TEST_NAME') ?: 'rehearsalbox_test',
            'user' => getenv('DB_TEST_USER') ?: 'root',
            'password' => getenv('DB_TEST_PASSWORD') ?: 'root',
        ];
        $container = (require __DIR__ . '/../../config/services.php')($config);

        self::assertInstanceOf(RestoreController::class, $container->get(RestoreController::class));

        $routes = require __DIR__ . '/../../config/routes.php';
        $found = [];
        foreach ($routes as $group) {
            foreach ($group as [$method, $path, $handler]) {
                if ($handler[0] === RestoreController::class) {
                    $found[] = "{$method} {$path} {$handler[1]}";
                }
            }
        }
        sort($found);
        self::assertSame(['GET /admin/restore page', 'POST /api/admin/restore start'], $found);
    }

    #[Test]
    public function testTheScriptIsCliOnlyAndNeverStartsWithoutAnExactConfirmation(): void
    {
        $script = __DIR__ . '/../../bin/restore-db.php';
        $source = (string) file_get_contents($script);

        self::assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        exec('php -l ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        self::assertSame(0, $code);

        exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        self::assertSame(1, $code, 'sans arguments : usage et échec, rien n\'est lancé');
    }
}
