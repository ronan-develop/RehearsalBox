<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #277 : étapes numérotées et barre de progression du déploiement (bin/lib/progress.sh), sans aucune valeur sensible. */
final class ProgressScriptTest extends TestCase
{
    private const LIB = __DIR__ . '/../../bin/lib/progress.sh';

    /** @return list<string> lignes de sortie */
    private function bash(string $script, string $env = ''): array
    {
        exec(sprintf('%s bash -c %s 2>&1', $env, escapeshellarg('source ' . self::LIB . '; ' . $script)), $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));

        return $lines;
    }

    #[Test]
    public function testEachStepIsNumberedWithAPercentageAndABarOfAFixedWidth(): void
    {
        $lines = array_values(array_filter($this->bash('progress_init 4; progress_step "Envoi"; progress_step "Migrations"'), static fn (string $l): bool => $l !== ''));

        self::assertMatchesRegularExpression('/^\[1\/4\] [█░]{20} +25% +Envoi/u', $lines[0]);
        self::assertMatchesRegularExpression('/^\[2\/4\] [█░]{20} +50% +Migrations/u', $lines[1]);
        self::assertSame(5, mb_substr_count(explode(' ', $lines[0])[1], '█'), 'un quart de 20 cases');
        self::assertSame(10, mb_substr_count(explode(' ', $lines[1])[1], '█'));
    }

    #[Test]
    public function testASkippedStepKeepsTheNumberingAndSaysSo(): void
    {
        $out = implode("\n", $this->bash('progress_init 3; progress_step "A"; progress_skip "B"; progress_step "C"'));

        self::assertStringContainsString('[2/3]', $out);
        self::assertMatchesRegularExpression('/\[2\/3\].*B.*ignorée/u', $out);
        self::assertMatchesRegularExpression('/\[3\/3\] [█]{20} +100% +C/u', $out);
    }

    #[Test]
    public function testTheEndLineReportsTheTotalDurationAndTheSteps(): void
    {
        $out = implode("\n", $this->bash('progress_init 2; progress_step "A"; progress_step "B"; progress_done "Déploiement terminé"'));

        self::assertMatchesRegularExpression('/Déploiement terminé.*\(\d+ ?s\)/u', $out);
    }

    #[Test]
    public function testNothingFromTheEnvironmentEverReachesTheOutput(): void
    {
        $out = implode("\n", $this->bash('progress_init 1; progress_step "Envoi de la release"; progress_done "fin"', 'RB_SSH_CONFIG=/secret/ssh_config RB_DOCROOT_LINK=docroot-secret RB_SSH_HOST=hote-secret'));

        foreach (['/secret/ssh_config', 'docroot-secret', 'hote-secret'] as $value) {
            self::assertStringNotContainsString($value, $out);
        }
    }

    #[Test]
    public function testTheDeployScriptUsesTheStepsInsteadOfBareEchoesAndItsTotalMatches(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../bin/deploy.sh');

        self::assertStringContainsString('source "$(dirname "$0")/lib/progress.sh"', $script);
        self::assertSame(0, preg_match_all('/^\s*say "/m', $script), 'plus d\'étape non numérotée');
        preg_match('/progress_init (\d+)/', $script, $total);
        self::assertSame((int) $total[1], preg_match_all('/progress_step "/', $script), 'le total annoncé = le nombre d\'étapes réelles');
        self::assertSame(1, preg_match_all('/progress_done "/', $script));
    }
}
