<?php

declare(strict_types=1);

namespace App\Tests\Association\Service;

use App\Association\Exception\AssociationTextValidationException;
use App\Association\Service\AssociationTextInputPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #472 : titre et texte d'un texte de l'association, validés AVANT la base ; l'échappement se fait à l'affichage. */
final class AssociationTextInputPolicyTest extends TestCase
{
    #[Test]
    public function testATitleAndABodyAreTrimmedAndLineEndingsNormalized(): void
    {
        [$title, $body] = (new AssociationTextInputPolicy())->parse(['title' => '  Statuts  ', 'body' => "  Article 1\r\nArticle 2\rFin\t.  "]);

        self::assertSame('Statuts', $title);
        self::assertSame("Article 1\nArticle 2\nFin\t.", $body);
    }

    #[Test]
    public function testMarkupIsKeptAsTypedBecauseEscapingHappensOnDisplay(): void
    {
        [$title, $body] = (new AssociationTextInputPolicy())->parse(['title' => '<b>Statuts</b>', 'body' => '<script>alert(1)</script>']);

        self::assertSame('<b>Statuts</b>', $title);
        self::assertSame('<script>alert(1)</script>', $body);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function refusals(): iterable
    {
        yield 'tout manque' => [[], ['title', 'body']];
        yield 'titre vide' => [['title' => '   ', 'body' => 'x'], ['title']];
        yield 'texte vide' => [['title' => 'T', 'body' => " \n "], ['body']];
        yield 'titre trop long' => [['title' => str_repeat('é', 151), 'body' => 'x'], ['title']];
        yield 'texte trop long' => [['title' => 'T', 'body' => str_repeat('a', 100_001)], ['body']];
        yield 'titre avec caractère de contrôle' => [['title' => "Sta\x00tuts", 'body' => 'x'], ['title']];
        yield 'titre sur deux lignes' => [['title' => "Sta\ntuts", 'body' => 'x'], ['title']];
        yield 'texte avec caractère de contrôle' => [['title' => 'T', 'body' => "a\x07b"], ['body']];
        yield 'tableau au lieu de texte' => [['title' => ['x'], 'body' => ['y']], ['title', 'body']];
        yield 'nombre au lieu de texte' => [['title' => 12, 'body' => 3.5], ['title', 'body']];
    }

    /** @param array<string, mixed> $input @param list<string> $fields */
    #[Test]
    #[DataProvider('refusals')]
    public function testInvalidInputIsRefusedFieldByField(array $input, array $fields): void
    {
        try {
            (new AssociationTextInputPolicy())->parse($input);
            self::fail('Saisie refusée attendue');
        } catch (AssociationTextValidationException $e) {
            self::assertSame($fields, array_keys($e->fields()));
        }
    }

    #[Test]
    public function testTheLimitsThemselvesAreAccepted(): void
    {
        [$title, $body] = (new AssociationTextInputPolicy())->parse(['title' => str_repeat('é', 150), 'body' => str_repeat('a', 100_000)]);

        self::assertSame(150, mb_strlen($title));
        self::assertSame(100_000, mb_strlen($body));
    }
}
