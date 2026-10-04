<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Controller\Api\PasswordResetApiController;
use App\Controller\PageController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordResetRoutesTest extends TestCase
{
    /** @return list<array{0: string, 1: string, 2: array{0: string, 1: string}}> */
    private function allRoutes(): array
    {
        $groups = require __DIR__ . '/../../config/routes.php';

        return [...$groups['pages'], ...$groups['api']];
    }

    #[Test]
    public function testPasswordResetPagesAreRegisteredAsGetRoutes(): void
    {
        self::assertContains(['GET', '/forgot-password', [PageController::class, 'forgotPassword']], $this->allRoutes());
        self::assertContains(['GET', '/reset-password', [PageController::class, 'resetPassword']], $this->allRoutes());
    }

    #[Test]
    public function testPasswordResetEndpointsAreRegisteredAsPostApiRoutes(): void
    {
        self::assertContains(['POST', '/api/auth/forgot-password', [PasswordResetApiController::class, 'forgotPassword']], $this->allRoutes());
        self::assertContains(['POST', '/api/auth/reset-password', [PasswordResetApiController::class, 'resetPassword']], $this->allRoutes());
    }
}
