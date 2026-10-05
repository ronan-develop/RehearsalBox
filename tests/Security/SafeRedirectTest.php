<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Retour après connexion : seules les pages de la messagerie sont des destinations permises (jamais de redirection ouverte). */
final class SafeRedirectTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function allowedProvider(): iterable
    {
        yield 'liste' => ['/messages'];
        yield 'archives' => ['/messages/archives'];
        yield 'conversation' => ['/messages/12'];
        yield 'conversation à dix chiffres' => ['/messages/2147483647'];
        yield 'nouvelle conversation' => ['/messages/new/7'];
    }

    #[Test]
    #[DataProvider('allowedProvider')]
    public function testAllowsOnlyMessagingPages(string $path): void
    {
        self::assertSame($path, SafeRedirect::afterLogin($path));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function refusedProvider(): iterable
    {
        yield 'site externe' => ['https://evil.example/messages/1'];
        yield 'sans schéma' => ['//evil.example/messages/1'];
        yield 'schéma javascript' => ['javascript:alert(1)'];
        yield 'antislash' => ['/\\evil.example'];
        yield 'autre page du site' => ['/admin/users'];
        yield 'API' => ['/api/conversations'];
        yield 'préfixe seulement' => ['/messagesevil'];
        yield 'identifiant zéro' => ['/messages/0'];
        yield 'identifiant avec zéros' => ['/messages/007'];
        yield 'identifiant trop long' => ['/messages/99999999999'];
        yield 'nouvelle conversation sans identifiant' => ['/messages/new/'];
        yield 'saut de ligne final' => ["/messages/1\n"];
        yield 'séparation d\'en-tête' => ["/messages/1\r\nSet-Cookie: x=1"];
        yield 'paramètres' => ['/messages/1?x=1'];
        yield 'fragment' => ['/messages/1#x'];
        yield 'tableau' => [['/messages/1']];
        yield 'null' => [null];
        yield 'vide' => [''];
    }

    #[Test]
    #[DataProvider('refusedProvider')]
    public function testRefusesEverythingElse(mixed $value): void
    {
        self::assertNull(SafeRedirect::afterLogin($value));
    }
}
