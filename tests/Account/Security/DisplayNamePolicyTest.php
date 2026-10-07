<?php

declare(strict_types=1);

namespace App\Tests\Account\Security;

use App\Account\Security\DisplayNamePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DisplayNamePolicyTest extends TestCase
{
    #[Test]
    public function testNormalizeTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Alice Martin', (new DisplayNamePolicy())->normalize("  Alice Martin \t\n"));
    }

    #[Test]
    public function testAcceptsOrdinaryNamesWithAccentsApostrophesAndHyphens(): void
    {
        $policy = new DisplayNamePolicy();

        foreach (['Alice', "Zoé O'Brien-Dupont", 'Jr Palaric', 'Vin T 1', '李小龍', 'Zoë 🎸'] as $name) {
            self::assertNull($policy->violation($name), $name);
        }
    }

    #[Test]
    public function testRefusesAnEmptyName(): void
    {
        self::assertNotNull((new DisplayNamePolicy())->violation(''));
    }

    #[Test]
    public function testCountsCharactersNotBytes(): void
    {
        $policy = new DisplayNamePolicy();

        self::assertNull($policy->violation(str_repeat('é', 100)), '100 caractères accentués = 200 octets : autorisé');
        self::assertNotNull($policy->violation(str_repeat('é', 101)));
    }

    #[Test]
    public function testRefusesControlAndFormattingCharacters(): void
    {
        $policy = new DisplayNamePolicy();

        foreach (["Ali\x00ce", "Ali\nce", "Ali\x1bce", "Ali\u{202E}ce", "Ali\u{2028}ce"] as $name) {
            self::assertNotNull($policy->violation($name), json_encode($name));
        }
    }

    #[Test]
    public function testViolationMessagesAreReadableFrenchSentences(): void
    {
        $policy = new DisplayNamePolicy();

        self::assertStringContainsString('requis', (string) $policy->violation(''));
        self::assertStringContainsString('100', (string) $policy->violation(str_repeat('a', 101)));
        self::assertStringContainsString('caractère', (string) $policy->violation("a\nb"));
    }
}
