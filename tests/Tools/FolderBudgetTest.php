<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Tools\FolderBudget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #287 : un dossier de src/ ne peut pas accumuler les classes sans limite (les sous-dossiers comptent à part). */
final class FolderBudgetTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folder-budget-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function remove(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->remove($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private function files(string $relativeDir, int $count): void
    {
        $path = $this->dir . ($relativeDir === '' ? '' : '/' . $relativeDir);
        is_dir($path) || mkdir($path, 0o777, true);
        for ($i = 1; $i <= $count; ++$i) {
            file_put_contents("{$path}/Class{$i}.php", "<?php\n");
        }
    }

    #[Test]
    public function testAFolderWithinTheCeilingIsFine(): void
    {
        $this->files('Service', 3);

        self::assertSame([], (new FolderBudget(3))->check($this->dir));
    }

    #[Test]
    public function testAFolderOverTheCeilingIsReportedWithItsCount(): void
    {
        $this->files('Service', 4);

        $problems = (new FolderBudget(3))->check($this->dir);

        self::assertCount(1, $problems);
        self::assertStringContainsString('Service', $problems[0]);
        self::assertStringContainsString('4 classes', $problems[0]);
        self::assertStringContainsString('plafond 3', $problems[0]);
    }

    #[Test]
    public function testSubfoldersDoNotCountTowardTheirParent(): void
    {
        $this->files('Repository', 2);
        $this->files('Repository/Contract', 3);

        self::assertSame([], (new FolderBudget(3))->check($this->dir));
    }

    #[Test]
    public function testAnExistingOversizedFolderIsToleratedAtItsFrozenCountButCannotGrow(): void
    {
        $this->files('Service', 5);

        self::assertSame([], (new FolderBudget(3, ['Service' => 5]))->check($this->dir));

        $this->files('Service', 6);
        $problems = (new FolderBudget(3, ['Service' => 5]))->check($this->dir);
        self::assertCount(1, $problems);
        self::assertStringContainsString('ne doit plus grossir', $problems[0]);
    }

    #[Test]
    public function testAnExceptionThatIsNoLongerNeededMustBeRemoved(): void
    {
        $this->files('Service', 2);
        $this->files('Gone', 0);

        $problems = (new FolderBudget(3, ['Service' => 5, 'Missing' => 4]))->check($this->dir);

        self::assertCount(2, $problems);
        self::assertStringContainsString('Service : exception obsolète', implode("\n", $problems));
        self::assertStringContainsString('Missing : exception obsolète', implode("\n", $problems));
    }

    #[Test]
    public function testTheRootFolderCountsToo(): void
    {
        $this->files('', 4);

        $problems = (new FolderBudget(3))->check($this->dir);

        self::assertCount(1, $problems);
        self::assertStringContainsString('(racine)', $problems[0]);
    }
}
