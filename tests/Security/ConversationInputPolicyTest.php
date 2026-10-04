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
        $policy = new ConversationInputPolicy();

        self::assertSame("a\nb", $policy->normalize("  a\nb \n"));
    }

    #[Test]
    public function testValidInputHasNoViolation(): void
    {
        $policy = new ConversationInputPolicy();

        self::assertSame([], $policy->violations('Créneau du jeudi', "Salut,\nà jeudi"));
        self::assertSame([], $policy->violations(null, 'Réponse seule'), 'une réponse n\'a pas de sujet');
        self::assertSame([], $policy->violations(str_repeat('é', 150), str_repeat('é', 5000)), 'bornes incluses, en caractères');
    }

    /** @return iterable<string, array{0: ?string, 1: string, 2: string}> */
    public static function invalidProvider(): iterable
    {
        yield 'sujet vide' => ['', 'Message', 'subject'];
        yield 'sujet trop long' => [str_repeat('x', 151), 'Message', 'subject'];
        yield 'sujet avec caractère de contrôle' => ["Sujet\x00caché", 'Message', 'subject'];
        yield 'sujet avec saut de ligne' => ["Sujet\nsur deux lignes", 'Message', 'subject'];
        yield 'sujet avec inversion de texte' => ["Sujet\u{202E}piégé", 'Message', 'subject'];
        yield 'message vide' => ['Sujet', '', 'message'];
        yield 'message trop long' => ['Sujet', str_repeat('x', 5001), 'message'];
        yield 'réponse vide' => [null, '', 'message'];
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function testInvalidInputIsReportedPerField(?string $subject, string $body, string $field): void
    {
        $violations = (new ConversationInputPolicy())->violations($subject, $body);

        self::assertArrayHasKey($field, $violations);
        self::assertCount(1, $violations);
    }

    #[Test]
    public function testAMessageMayContainLineBreaks(): void
    {
        self::assertSame([], (new ConversationInputPolicy())->violations(null, "ligne 1\r\nligne 2\n\nligne 4"));
    }
}
