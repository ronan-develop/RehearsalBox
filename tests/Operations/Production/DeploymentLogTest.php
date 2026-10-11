<?php

declare(strict_types=1);

namespace App\Tests\Operations\Production;

use App\Operations\Production\DeploymentLog;
use PHPUnit\Framework\TestCase;

final class DeploymentLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-deplog-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $override */
    private function line(string $release = '20261011093000-abc1234', array $override = []): string
    {
        return (string) json_encode($override + ['v' => 1, 'release' => $release, 'commit' => 'abc1234', 'startedAt' => '2026-10-11T09:30:00Z', 'durationS' => 184, 'outcome' => 'ok', 'switched' => true, 'checks' => 'ci']);
    }

    private function log(string ...$lines): DeploymentLog
    {
        file_put_contents($this->dir . '/d.jsonl', implode("\n", $lines) . "\n");

        return new DeploymentLog($this->dir . '/d.jsonl');
    }

    public function testNoFileMeansNoDeployment(): void
    {
        self::assertSame([], (new DeploymentLog($this->dir . '/absent.jsonl'))->recent());
        self::assertSame([], (new DeploymentLog(null))->recent());
    }

    public function testALineBecomesADeployment(): void
    {
        $deployments = $this->log($this->line())->recent();

        self::assertCount(1, $deployments);
        self::assertSame('20261011093000-abc1234', $deployments[0]->release);
        self::assertSame(184, $deployments[0]->durationSeconds);
        self::assertTrue($deployments[0]->succeeded);
        self::assertTrue($deployments[0]->switched);
        self::assertSame('2026-10-11T09:30:00Z', $deployments[0]->startedAt->format('Y-m-d\TH:i:s\Z'));
    }

    public function testNewestFirstAndLimited(): void
    {
        $log = $this->log($this->line('20261009000000-aaa1111'), $this->line('20261010000000-bbb2222'), $this->line('20261011000000-ccc3333'));

        self::assertSame(['20261011000000-ccc3333', '20261010000000-bbb2222'], array_map(static fn ($d): string => $d->release, $log->recent(2)));
    }

    public function testTruncatedOrInvalidLinesAreIgnored(): void
    {
        $log = $this->log(
            '{"v":1,"release":"2026101',
            'pas du json',
            $this->line('20261010000000-bbb2222'),
            $this->line('../../etc/passwd'),
            $this->line(override: ['outcome' => 'peut-être']),
            $this->line(override: ['durationS' => '184']),
            $this->line(override: ['durationS' => -3]),
            $this->line(override: ['startedAt' => '2026-13-45T99:00:00Z']),
            $this->line(override: ['v' => 2]),
            $this->line(override: ['checks' => '$(whoami)']),
            $this->line(override: ['switched' => 'oui']),
            '[1,2,3]',
            '',
        );

        self::assertSame(['20261010000000-bbb2222'], array_map(static fn ($d): string => $d->release, $log->recent()));
    }

    public function testOnlyTheEndOfAHugeJournalIsRead(): void
    {
        $lines = [];
        for ($i = 0; $i < 60000; ++$i) {
            $lines[] = $this->line(sprintf('20261011%06d-abc1234', $i % 235959));
        }
        file_put_contents($this->dir . '/d.jsonl', implode("\n", $lines) . "\n");
        $before = memory_get_peak_usage();

        $deployments = (new DeploymentLog($this->dir . '/d.jsonl'))->recent(20);

        self::assertCount(20, $deployments);
        self::assertLessThan(8_000_000, memory_get_peak_usage() - $before, 'le fichier n\'est pas lu en entier');
    }

    public function testASymlinkedJournalIsRefused(): void
    {
        $this->log($this->line());
        symlink($this->dir . '/d.jsonl', $this->dir . '/link.jsonl');

        self::assertSame([], (new DeploymentLog($this->dir . '/link.jsonl'))->recent());
    }
}
