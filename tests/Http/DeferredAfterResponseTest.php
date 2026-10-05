<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AfterResponseInterface;
use App\Http\DeferredAfterResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeferredAfterResponseTest extends TestCase
{
    #[Test]
    public function testTasksOnlyRunWhenTheResponseIsDoneAndInTheOrderTheyWereDeferred(): void
    {
        $log = [];
        $after = new DeferredAfterResponse(static function () use (&$log): void {
            $log[] = 'réponse terminée';
        });

        $after->defer(static function () use (&$log): void {
            $log[] = 'premier';
        });
        $after->defer(static function () use (&$log): void {
            $log[] = 'second';
        });
        self::assertSame([], $log, 'rien ne s’exécute avant la fin de la réponse');

        $after->run();

        self::assertSame(['réponse terminée', 'premier', 'second'], $log, 'la réponse est libérée AVANT le premier travail');
        self::assertInstanceOf(AfterResponseInterface::class, $after);
    }

    #[Test]
    public function testWithoutTasksTheResponseIsNotTouched(): void
    {
        $finished = 0;
        $after = new DeferredAfterResponse(static function () use (&$finished): void {
            ++$finished;
        });

        $after->run();

        self::assertSame(0, $finished);
    }

    #[Test]
    public function testAFailingTaskNeverBreaksTheOthersNorTheRequestAndLeavesNoDataInTheLog(): void
    {
        $ran = [];
        $after = new DeferredAfterResponse(static function (): void {
        });
        $after->defer(static function (): void {
            throw new \RuntimeException('alice@rehearsalbox.test jeton-secret');
        });
        $after->defer(static function () use (&$ran): void {
            $ran[] = 'suivant';
        });
        $logged = $this->captureErrorLog(static fn () => $after->run());

        self::assertSame(['suivant'], $ran);
        self::assertStringContainsString('RuntimeException', $logged, 'la classe de l’erreur suffit au diagnostic');
        self::assertStringNotContainsString('alice', $logged);
        self::assertStringNotContainsString('jeton-secret', $logged);
    }

    #[Test]
    public function testTasksRunOnlyOnceEvenIfRunIsCalledTwice(): void
    {
        $count = 0;
        $after = new DeferredAfterResponse(static function (): void {
        });
        $after->defer(static function () use (&$count): void {
            ++$count;
        });

        $after->run();
        $after->run();

        self::assertSame(1, $count);
    }

    /** @param callable(): void $action */
    private function captureErrorLog(callable $action): string
    {
        $file = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $file);
        try {
            $action();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $content = (string) file_get_contents($file);
        unlink($file);

        return $content;
    }
}
