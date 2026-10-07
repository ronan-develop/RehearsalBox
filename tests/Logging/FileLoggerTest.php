<?php

declare(strict_types=1);

namespace App\Tests\Logging;

use App\Logging\FileLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;

final class FileLoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-log-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function logger(string $level = LogLevel::WARNING, int $maxBytes = 1_000_000, int $keep = 3): FileLogger
    {
        return new FileLogger($this->dir . '/app.log', new MockClock('2026-10-07 10:00:00 UTC'), $level, $maxBytes, $keep);
    }

    private function content(string $name = 'app.log'): string
    {
        return (string) file_get_contents($this->dir . '/' . $name);
    }

    #[Test]
    public function testALineCarriesTheDateTheLevelTheMessageAndTheContextAsJson(): void
    {
        $this->logger()->error('Envoi en échec', ['conversation' => 12, 'exception' => 'RuntimeException']);

        self::assertSame(
            '2026-10-07T10:00:00+00:00 ERROR Envoi en échec {"conversation":12,"exception":"RuntimeException"}' . "\n",
            $this->content(),
        );
    }

    #[Test]
    public function testALineWithoutContextHasNoEmptyBraces(): void
    {
        $this->logger()->warning('Rien à dire');

        self::assertSame("2026-10-07T10:00:00+00:00 WARNING Rien à dire\n", $this->content());
    }

    #[Test]
    public function testLevelsBelowTheThresholdAreDropped(): void
    {
        $logger = $this->logger(LogLevel::WARNING);
        $logger->info('Bavard');
        $logger->debug('Très bavard');
        $logger->warning('Gardé');

        self::assertSame(1, substr_count($this->content(), "\n"));
        self::assertStringNotContainsString('Bavard', $this->content());
    }

    #[Test]
    public function testAnUnknownLevelIsRejectedLikeAnyPsr3Logger(): void
    {
        $this->expectException(\Psr\Log\InvalidArgumentException::class);

        $this->logger()->log('bizarre', 'x');
    }

    #[Test]
    public function testALineCannotBeForgedByLineBreaksInTheMessageOrTheContext(): void
    {
        $this->logger()->error("Ligne 1\n2026-10-07T10:00:00+00:00 CRITICAL Faux", ["k\n" => "v\r\nfaux"]);

        self::assertSame(1, substr_count($this->content(), "\n"));
    }

    #[Test]
    public function testOnlyScalarContextValuesAreKeptNeverAnObjectNorAnArray(): void
    {
        $this->logger()->error('Contexte', ['ok' => true, 'n' => 1.5, 'obj' => new \RuntimeException('alice@rehearsalbox.test'), 'tab' => ['alice@rehearsalbox.test'], 'rien' => null]);

        self::assertStringContainsString('"ok":true', $this->content());
        self::assertStringContainsString('"n":1.5', $this->content());
        self::assertStringContainsString('"obj":"RuntimeException"', $this->content(), 'un objet est réduit à sa classe');
        self::assertStringNotContainsString('alice', $this->content());
        self::assertStringNotContainsString('"tab"', $this->content());
    }

    #[Test]
    public function testTheFileIsRotatedWhenItReachesTheMaximumSizeAndOldOnesAreDropped(): void
    {
        $logger = $this->logger(maxBytes: 200, keep: 2);
        foreach (range(1, 12) as $i) {
            $logger->error(sprintf('Ligne numéro %02d avec un peu de remplissage pour grossir', $i));
        }

        self::assertFileExists($this->dir . '/app.log.1');
        self::assertFileExists($this->dir . '/app.log.2');
        self::assertFileDoesNotExist($this->dir . '/app.log.3', 'au plus « keep » fichiers archivés');
        self::assertStringContainsString('numéro 12', $this->content(), 'la dernière ligne est dans le fichier courant');
        self::assertLessThanOrEqual(400, filesize($this->dir . '/app.log'));
    }

    #[Test]
    public function testAnUnwritableDestinationNeverBreaksTheSite(): void
    {
        $logger = new FileLogger('/proc/rehearsalbox/inaccessible/app.log', new MockClock(), LogLevel::DEBUG);

        $logger->critical('Le disque est plein');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function testTheDirectoryIsCreatedOnFirstWriteWithPrivatePermissions(): void
    {
        $this->logger()->error('Premier message');

        self::assertSame('0750', substr(sprintf('%o', fileperms($this->dir)), -4));
    }
}
