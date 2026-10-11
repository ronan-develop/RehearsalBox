<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #396 : journal des déploiements (bin/lib/deploy-log.sh). Une ligne JSON par déploiement, composée de champs validés un par un ;
 * rien de l'environnement (RB_*, HOME, chemins) n'y entre ; un échec d'écriture ne fait JAMAIS échouer le déploiement.
 */
final class DeployLogScriptTest extends TestCase
{
    private const LIB = __DIR__ . '/../../bin/lib/deploy-log.sh';

    private function bash(string $script, string $env = ''): string
    {
        exec(sprintf('%s bash -c %s 2>&1', $env, escapeshellarg('source ' . self::LIB . '; ' . $script)), $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));

        return implode("\n", $lines);
    }

    private function line(string $release = '20261011093000-abc1234', string $commit = 'abc1234', string $at = '2026-10-11T09:30:00Z', string $duration = '184', string $outcome = 'ok', string $switched = '1', string $checks = 'ci'): string
    {
        return $this->bash('deploy_log_line ' . implode(' ', array_map('escapeshellarg', [$release, $commit, $at, $duration, $outcome, $switched, $checks])));
    }

    #[Test]
    public function testTheLineIsValidJsonWithTheExpectedFields(): void
    {
        $data = json_decode($this->line(), true);

        self::assertSame(['v' => 1, 'release' => '20261011093000-abc1234', 'commit' => 'abc1234', 'startedAt' => '2026-10-11T09:30:00Z', 'durationS' => 184, 'outcome' => 'ok', 'switched' => true, 'checks' => 'ci'], $data);
    }

    #[Test]
    public function testNotSwitchedAndFailedAreRepresented(): void
    {
        $data = json_decode($this->line(outcome: 'failed', switched: '0', checks: 'skipped'), true);

        self::assertSame('failed', $data['outcome']);
        self::assertFalse($data['switched']);
        self::assertSame('skipped', $data['checks']);
    }

    #[Test]
    public function testAnyInvalidFieldGivesNoLineAtAll(): void
    {
        $invalid = [
            'release' => $this->line(release: '../../etc/passwd'),
            'commit' => $this->line(commit: 'abc"; rm -rf /'),
            'startedAt' => $this->line(at: 'hier'),
            'duration' => $this->line(duration: '12s'),
            'outcome' => $this->line(outcome: 'peut-être'),
            'switched' => $this->line(switched: 'oui'),
            'checks' => $this->line(checks: '$(whoami)'),
        ];

        foreach ($invalid as $field => $output) {
            self::assertSame('', $output, $field);
        }
    }

    #[Test]
    public function testNothingFromTheEnvironmentReachesTheLine(): void
    {
        $line = $this->bash('deploy_log_line 20261011093000-abc1234 abc1234 2026-10-11T09:30:00Z 5 ok 1 ci', 'RB_SSH_CONFIG=/secret/ssh_config RB_DOCROOT_LINK=docroot-secret RB_SSH_HOST=hote-secret HOME=/home/secret-home');

        foreach (['/secret/ssh_config', 'docroot-secret', 'hote-secret', 'secret-home'] as $value) {
            self::assertStringNotContainsString($value, $line);
        }
    }

    #[Test]
    public function testAnEmptyLineIsNeverSentToTheServer(): void
    {
        $out = $this->bash('rb_ssh() { echo "APPEL"; cat; }; BASE=x; deploy_log_append ""');

        self::assertStringNotContainsString('APPEL', $out);
    }

    #[Test]
    public function testALineIsSentOnStandardInputAndTheJournalIsRotatedTo200Lines(): void
    {
        $out = $this->bash('rb_ssh() { printf "ARGS:%s\n" "$*"; cat; }; BASE=rehearsalbox; deploy_log_append "{\"v\":1}"');

        self::assertStringContainsString('{"v":1}', $out);
        self::assertStringContainsString('umask 077', $out);
        self::assertStringContainsString('shared/deployments.jsonl', $out);
        self::assertStringContainsString('tail -n 200', $out);
        self::assertStringContainsString('ConnectTimeout=10', $out);
    }

    #[Test]
    public function testAFailingWriteNeverFailsTheDeployment(): void
    {
        $out = $this->bash('rb_ssh() { cat >/dev/null; return 255; }; BASE=x; deploy_log_append "{\"v\":1}"; echo "code=$?"');

        self::assertStringContainsString('code=0', $out);
        self::assertStringContainsString('déploiement non affecté', $out);
    }

    #[Test]
    public function testFinishBuildsTheOutcomeFromTheExitCodeAndTheSwitchState(): void
    {
        $env = 'rb_ssh() { cat; }; BASE=x; release=20261011093000-abc1234; commit=abc1234; deploy_started=$(( $(date +%s) - 12 )); switched=0; checks_mode=local;';

        $failed = json_decode(trim(explode("\n", $this->bash($env . ' deploy_log_finish 1'))[0]), true);
        self::assertSame('failed', $failed['outcome']);
        self::assertFalse($failed['switched']);
        self::assertGreaterThanOrEqual(12, $failed['durationS']);
        self::assertSame('local', $failed['checks']);

        $okSwitched = json_decode(trim(explode("\n", $this->bash($env . ' switched=1; deploy_log_finish 0'))[0]), true);
        self::assertSame('ok', $okSwitched['outcome']);
        self::assertTrue($okSwitched['switched']);

        $postSwitchFailure = json_decode(trim(explode("\n", $this->bash($env . ' switched=1; deploy_log_finish 1'))[0]), true);
        self::assertSame('failed', $postSwitchFailure['outcome']);
        self::assertTrue($postSwitchFailure['switched'], 'la bascule est faite mais le contrôle a échoué');
    }

    #[Test]
    public function testTheDeployScriptWiresTheJournalAroundTheWholeRun(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../bin/deploy.sh');

        self::assertStringContainsString('source "$(dirname "$0")/lib/deploy-log.sh"', $script);
        self::assertStringContainsString("trap 'deploy_log_finish \$?' EXIT", $script);
        self::assertLessThan(strpos($script, "trap 'deploy_log_finish"), strpos($script, 'release="$(date'), 'le piège est posé une fois la release connue');
        $switch = strpos($script, 'Bascule vers');
        self::assertNotFalse(strpos($script, 'switched=1'));
        self::assertGreaterThan($switch, strpos($script, 'switched=1'), 'marqué basculé seulement après la bascule');
        self::assertLessThan(strpos($script, 'Purge d\'OPcache'), strpos($script, 'switched=1'));
    }

    #[Test]
    public function testTheTrapKeepsTheOriginalExitCodeEvenWhenTheJournalFails(): void
    {
        $script = 'set -euo pipefail; source ' . self::LIB . '; rb_ssh() { cat >/dev/null; return 255; }; BASE=x; '
            . 'release=20261011093000-abc1234; commit=abc1234; deploy_started=$(date +%s); switched=0; checks_mode=ci; '
            . "trap 'deploy_log_finish \$?' EXIT; false";
        exec(sprintf('bash -c %s 2>&1', escapeshellarg($script)), $lines, $code);

        self::assertSame(1, $code, 'le code de sortie du déploiement est conservé');
        self::assertStringContainsString('déploiement non affecté', implode("\n", $lines));
    }

    #[Test]
    public function testAnEarlyStopBeforeTheSwitchIsJournaledAsFailedAndNotSwitched(): void
    {
        $script = 'set -euo pipefail; source ' . self::LIB . '; rb_ssh() { cat; }; BASE=x; '
            . 'release=20261011093000-abc1234; commit=abc1234; deploy_started=$(date +%s); switched=0; checks_mode=local; '
            . "trap 'deploy_log_finish \$?' EXIT; exit 7";
        exec(sprintf('bash -c %s 2>&1', escapeshellarg($script)), $lines, $code);

        self::assertSame(7, $code);
        $data = json_decode($lines[0], true);
        self::assertSame('failed', $data['outcome']);
        self::assertFalse($data['switched']);
    }
}
