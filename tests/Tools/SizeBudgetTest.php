<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Tools\SizeBudget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SizeBudgetTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/size-budget-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.php') ?: []);
        rmdir($this->dir);
    }

    #[Test]
    public function testASmallClassRespectsTheBudget(): void
    {
        $this->write('Small.php', $this->classWith(publicMethods: 2, extraLines: 0));

        self::assertSame([], (new SizeBudget(maxLines: 50, maxPublicMethods: 3))->check($this->dir));
    }

    #[Test]
    public function testATooLongClassIsReported(): void
    {
        $this->write('Long.php', $this->classWith(publicMethods: 1, extraLines: 80));

        $problems = (new SizeBudget(maxLines: 50, maxPublicMethods: 3))->check($this->dir);

        self::assertCount(1, $problems);
        self::assertStringContainsString('Long.php', $problems[0]);
        self::assertStringContainsString('lignes', $problems[0]);
    }

    #[Test]
    public function testTooManyPublicMethodsAreReportedButTheConstructorDoesNotCount(): void
    {
        $this->write('Wide.php', $this->classWith(publicMethods: 4, extraLines: 0, constructor: true));
        $this->write('Narrow.php', $this->classWith(publicMethods: 3, extraLines: 0, constructor: true));

        $problems = (new SizeBudget(maxLines: 500, maxPublicMethods: 3))->check($this->dir);

        self::assertCount(1, $problems);
        self::assertStringContainsString('Wide.php', $problems[0]);
        self::assertStringContainsString('méthodes publiques', $problems[0]);
    }

    #[Test]
    public function testAKnownOversizedClassIsToleratedAtItsRecordedSize(): void
    {
        $this->write('Legacy.php', $this->classWith(publicMethods: 5, extraLines: 80));
        $recorded = substr_count((string) file_get_contents($this->dir . '/Legacy.php'), "\n") + 1;

        $budget = new SizeBudget(maxLines: 50, maxPublicMethods: 3, exceptions: ['Legacy.php' => ['lines' => $recorded, 'public' => 5]]);

        self::assertSame([], $budget->check($this->dir));
    }

    #[Test]
    public function testAKnownOversizedClassMayNotGrowAnyMore(): void
    {
        $this->write('Legacy.php', $this->classWith(publicMethods: 6, extraLines: 90));

        $budget = new SizeBudget(maxLines: 50, maxPublicMethods: 3, exceptions: ['Legacy.php' => ['lines' => 60, 'public' => 5]]);
        $problems = $budget->check($this->dir);

        self::assertCount(1, $problems);
        self::assertStringContainsString('ne doit plus grossir', $problems[0]);
    }

    #[Test]
    public function testAnExceptionBecomesObsoleteOnceTheClassIsBackUnderBudget(): void
    {
        $this->write('Legacy.php', $this->classWith(publicMethods: 1, extraLines: 0));

        $budget = new SizeBudget(maxLines: 50, maxPublicMethods: 3, exceptions: ['Legacy.php' => ['lines' => 400, 'public' => 20]]);
        $problems = $budget->check($this->dir);

        self::assertCount(1, $problems);
        self::assertStringContainsString('exception obsolète', $problems[0]);
    }

    private function write(string $name, string $code): void
    {
        file_put_contents($this->dir . '/' . $name, $code);
    }

    private function classWith(int $publicMethods, int $extraLines, bool $constructor = false): string
    {
        $code = "<?php\n\nfinal class Sample\n{\n";
        if ($constructor) {
            $code .= "    public function __construct()\n    {\n    }\n\n";
        }
        for ($i = 0; $i < $publicMethods; ++$i) {
            $code .= "    public function method{$i}(): void\n    {\n    }\n\n";
        }
        $code .= str_repeat("    // ligne\n", $extraLines) . "}\n";

        return $code;
    }
}
