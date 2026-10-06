<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #300 : le déploiement ne rejoue pas les tests quand la CI est verte sur le commit ; au moindre doute, il retombe sur les contrôles locaux. */
final class CiStatusScriptTest extends TestCase
{
    private const LIB = __DIR__ . '/../../bin/lib/ci-status.sh';

    private string $fakeGh;

    protected function setUp(): void
    {
        $this->fakeGh = tempnam(sys_get_temp_dir(), 'fakegh');
        file_put_contents($this->fakeGh, "#!/usr/bin/env bash\n[ \"\${FAKE_FAIL:-0}\" = 1 ] && exit 1\nprintf '%s' \"\$FAKE_RUNS\"\n");
        chmod($this->fakeGh, 0o700);
    }

    protected function tearDown(): void
    {
        @unlink($this->fakeGh);
    }

    /** @return int code de sortie de ci_green_for_commit */
    private function check(string $runs, bool $ghFails = false, ?string $gh = null): int
    {
        $env = sprintf('RB_GH=%s FAKE_RUNS=%s FAKE_FAIL=%d', escapeshellarg($gh ?? $this->fakeGh), escapeshellarg($runs), $ghFails ? 1 : 0);
        exec(sprintf('%s bash -c %s 2>&1', $env, escapeshellarg('source ' . self::LIB . '; ci_green_for_commit abc123')), $out, $code);

        return $code;
    }

    #[Test]
    public function testItIsGreenWhenEveryRunOfTheCommitCompletedSuccessfully(): void
    {
        self::assertSame(0, $this->check("completed:success\ncompleted:success\n"));
    }

    #[Test]
    public function testItIsNotGreenWhileARunIsStillGoingOrAfterAFailure(): void
    {
        self::assertNotSame(0, $this->check("completed:success\nin_progress:\n"));
        self::assertNotSame(0, $this->check("completed:success\ncompleted:failure\n"));
        self::assertNotSame(0, $this->check("completed:cancelled\n"));
    }

    #[Test]
    public function testNoRunAtAllIsNotGreen(): void
    {
        self::assertNotSame(0, $this->check(''));
    }

    #[Test]
    public function testAnUnavailableOrFailingGhIsNotGreen(): void
    {
        self::assertNotSame(0, $this->check("completed:success\n", true));
        self::assertNotSame(0, $this->check("completed:success\n", false, '/nonexistent/gh'));
    }

    #[Test]
    public function testTheDeployScriptSkipsTheTestsOnlyWhenTheCiIsGreenAndKeepsTheAudit(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../bin/deploy.sh');

        self::assertStringContainsString('source "$(dirname "$0")/lib/ci-status.sh"', $script);
        self::assertStringContainsString('ci_green_for_commit "$(git rev-parse HEAD)"', $script);
        self::assertStringContainsString('RB_FORCE_LOCAL_CHECKS', $script, 'on peut toujours exiger les contrôles locaux complets');
        self::assertSame(1, preg_match_all('/^\s*composer audit$/m', $script), 'l\'audit des dépendances n\'est jamais sauté, une seule fois');
    }
}
