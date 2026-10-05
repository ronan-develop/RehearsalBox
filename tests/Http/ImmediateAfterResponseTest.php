<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AfterResponseInterface;
use App\Http\ImmediateAfterResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImmediateAfterResponseTest extends TestCase
{
    #[Test]
    public function testTheTaskRunsRightAwayAndAFailureNeverEscapes(): void
    {
        $after = new ImmediateAfterResponse();
        $ran = false;

        $after->defer(static function () use (&$ran): void {
            $ran = true;
        });
        $previous = ini_set('error_log', '/dev/null');
        $after->defer(static function (): void {
            throw new \RuntimeException('boom');
        });
        ini_set('error_log', (string) $previous);

        self::assertTrue($ran);
        self::assertInstanceOf(AfterResponseInterface::class, $after);
    }
}
