<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #266 : pas de désabonnement général des e-mails ; seule la sourdine d'une conversation coupe les e-mails. */
final class NoGeneralUnsubscribeRouteTest extends TestCase
{
    #[Test]
    public function testThereIsNoAccountNotificationsRoute(): void
    {
        $groups = require __DIR__ . '/../../config/routes.php';
        $paths = array_map(static fn (array $route): string => $route[1], [...$groups['pages'], ...$groups['api']]);

        self::assertNotContains('/api/account/notifications', $paths);
        self::assertContains('/api/conversations/{id}/mute', $paths, 'la sourdine par conversation reste');
    }
}
