<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #292 : les deux sortes de demandes se reconnaissent d'un coup d'œil (couleur ET libellé), le validateur attendu est lisible. */
final class RequestKindBadgeCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/dashboard.css');
    }

    #[Test]
    public function testTheTwoKindsHaveTheirOwnColour(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-badge-kind--reservation\s*\{[^}]*color:\s*var\(--rb-accent\)[^}]*border-color:/s', $css);
        self::assertMatchesRegularExpression('/\.rb-badge-kind--echange\s*\{[^}]*color:\s*var\(--rb-accent-2\)[^}]*border-color:/s', $css);
    }

    #[Test]
    public function testTheKindBadgeIsAnUppercaseLabelAndTheValidatorLineIsReadable(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-badge-kind\s*\{[^}]*text-transform:\s*uppercase/s', $css);
        self::assertMatchesRegularExpression('/\.rb-exception-card-validator\s*\{[^}]*color:\s*var\(--rb-text-2\)/s', $css);
    }
}
