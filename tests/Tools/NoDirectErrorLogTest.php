<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Garde-fou de #193 : tout incident passe par le logger PSR-3 injecté, jamais par un `error_log()` dispersé. */
final class NoDirectErrorLogTest extends TestCase
{
    #[Test]
    public function testNoSourceFileCallsErrorLogDirectly(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && preg_match('/\berror_log\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = substr($file->getPathname(), strlen(__DIR__ . '/../../'));
            }
        }

        self::assertSame([], $offenders, 'Injecter un Psr\Log\LoggerInterface au lieu d\'appeler error_log().');
    }
}
