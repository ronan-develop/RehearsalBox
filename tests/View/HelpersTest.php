<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/View/helpers.php';

final class HelpersTest extends TestCase
{
    public function testFormatTimeStripsSecondsFromHhMmSs(): void
    {
        self::assertSame('18:00', formatTime('18:00:00'));
    }

    public function testFormatTimeHandlesMaxCeiling(): void
    {
        self::assertSame('23:30', formatTime('23:30:00'));
    }

    public function testFormatTimeIsIdempotentOnAlreadyShortFormat(): void
    {
        self::assertSame('18:00', formatTime('18:00'));
    }
}
