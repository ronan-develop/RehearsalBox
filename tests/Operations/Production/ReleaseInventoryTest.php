<?php

declare(strict_types=1);

namespace App\Tests\Operations\Production;

use App\Operations\Production\ReleaseInventory;
use PHPUnit\Framework\TestCase;

final class ReleaseInventoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-OPSCANARY-rel-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            if (is_link($entry) || is_file($entry)) {
                unlink($entry);
                continue;
            }
            foreach (glob($entry . '/*') ?: [] as $inner) {
                unlink($inner);
            }
            rmdir($entry);
        }
        rmdir($this->dir);
    }

    private function release(string $id, bool $withMarker = true): void
    {
        mkdir($this->dir . '/' . $id, 0o700);
        if ($withMarker) {
            file_put_contents($this->dir . '/' . $id . '/RELEASE', $id);
        }
    }

    private function inventory(string $current, ?string $dir = null): ReleaseInventory
    {
        return new ReleaseInventory($dir ?? $this->dir, $current, new \DateTimeZone('Europe/Paris'));
    }

    public function testMissingDirectoryGivesNoRelease(): void
    {
        self::assertSame([], $this->inventory('x', $this->dir . '/absent')->releases());
        self::assertSame([], (new ReleaseInventory(null, 'x', new \DateTimeZone('UTC')))->releases());
    }

    public function testReleasesAreListedNewestFirstWithTheCurrentOneAndTheRollbackTarget(): void
    {
        $this->release('20261009000000-aaa1111');
        $this->release('20261010000000-bbb2222');
        $this->release('20261011000000-ccc3333');

        $releases = $this->inventory('20261011000000-ccc3333')->releases();

        self::assertSame(['20261011000000-ccc3333', '20261010000000-bbb2222', '20261009000000-aaa1111'], array_column($releases, 'id'));
        self::assertSame([true, false, false], array_column($releases, 'current'));
        self::assertSame([false, true, false], array_column($releases, 'rollbackTarget'), 'la cible est celle juste avant l\'active, comme rollback.sh');
        self::assertSame('ccc3333', $releases[0]['commit']);
        self::assertInstanceOf(\DateTimeImmutable::class, $releases[0]['at']);
    }

    public function testASingleReleaseHasNoRollbackTarget(): void
    {
        $this->release('20261011000000-ccc3333');

        self::assertSame([false], array_column($this->inventory('20261011000000-ccc3333')->releases(), 'rollbackTarget'));
    }

    public function testWhenTheActiveReleaseIsTheOldestThereIsNoTarget(): void
    {
        $this->release('20261010000000-bbb2222');
        $this->release('20261011000000-ccc3333');

        self::assertNotContains(true, array_column($this->inventory('20261010000000-bbb2222')->releases(), 'rollbackTarget'));
    }

    public function testHostileNamesFilesAndSymlinksAreIgnored(): void
    {
        $this->release('20261010000000-bbb2222');
        mkdir($this->dir . '/..%2f..%2fetc', 0o700);
        mkdir($this->dir . '/20261011000000-XYZ', 0o700);
        mkdir($this->dir . '/<script>', 0o700);
        file_put_contents($this->dir . '/20261012000000-ddd4444', 'un fichier, pas un dossier');
        symlink($this->dir . '/20261010000000-bbb2222', $this->dir . '/20261013000000-eee5555');

        $releases = $this->inventory('20261010000000-bbb2222')->releases();

        self::assertSame(['20261010000000-bbb2222'], array_column($releases, 'id'));
        rmdir($this->dir . '/..%2f..%2fetc');
        rmdir($this->dir . '/20261011000000-XYZ');
        rmdir($this->dir . '/<script>');
    }

    public function testAMissingMarkerGivesNoDateButStillListsTheRelease(): void
    {
        $this->release('20261011000000-ccc3333', withMarker: false);

        self::assertNull($this->inventory('20261011000000-ccc3333')->releases()[0]['at']);
    }
}
