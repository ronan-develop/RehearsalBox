<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ConversationInputPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationInputPolicyTest extends TestCase
{
    #[Test]
    public function testNormalizeTrimsSurroundingWhitespaceOnly(): void
    {
        self::assertSame("a\nb", (new ConversationInputPolicy())->normalize("  a\nb \n"));
    }

    #[Test]
    public function testValidTitleAndBodyHaveNoViolation(): void
    {
        $policy = new ConversationInputPolicy();

        self::assertNull($policy->titleViolation('Concert du 12'));
        self::assertNull($policy->titleViolation(str_repeat('é', 150)), 'borne incluse, en caractères');
        self::assertNull($policy->bodyViolation("Salut,\r\nà jeudi\n\nmerci"), 'un message peut contenir des sauts de ligne');
        self::assertNull($policy->bodyViolation(str_repeat('é', 5000)));
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidTitleProvider(): iterable
    {
        yield 'trop long' => [str_repeat('x', 151)];
        yield 'caractère de contrôle' => ["Titre\x00caché"];
        yield 'saut de ligne' => ["Titre\nsur deux lignes"];
        yield 'inversion de texte' => ["Titre\u{202E}piégé"];
        yield 'séparateur de ligne unicode' => ["Titre\u{2028}suite"];
    }

    #[Test]
    #[DataProvider('invalidTitleProvider')]
    public function testInvalidTitleIsRefused(string $title): void
    {
        self::assertNotNull((new ConversationInputPolicy())->titleViolation($title));
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidBodyProvider(): iterable
    {
        yield 'vide' => [''];
        yield 'trop long' => [str_repeat('x', 5001)];
    }

    #[Test]
    #[DataProvider('invalidBodyProvider')]
    public function testInvalidBodyIsRefused(string $body): void
    {
        self::assertNotNull((new ConversationInputPolicy())->bodyViolation($body));
    }
}
