<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Retour après connexion : seules les pages de la messagerie, de l'exploitation, d'un échange et des réservations à valider sont des destinations permises (jamais de redirection ouverte). */
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
        yield 'exploitation' => ['/admin/ops'];
        yield 'exploitation, production' => ['/admin/ops/production'];
        yield 'mesures' => ['/admin/metrics'];
        yield 'mesures, sécurité' => ['/admin/metrics/security'];
        yield 'mesures, charge' => ['/admin/metrics/load'];
        yield 'demande d\'échange' => ['/exchanges/12'];
        yield 'demande d\'échange à dix chiffres' => ['/exchanges/2147483647'];
        yield 'réservations à valider' => ['/admin/bookings'];
        yield 'comptabilité, lien du rappel au trésorier' => ['/admin/accounting'];
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
        yield 'restauration, jamais depuis un lien' => ['/admin/restore'];
        yield 'exploitation avec barre finale' => ['/admin/ops/'];
        yield 'exploitation, préfixe seulement' => ['/admin/opsevil'];
        yield 'exploitation en majuscules' => ['/ADMIN/OPS'];
        yield 'exploitation avec saut de ligne' => ["/admin/ops\n"];
        yield 'exploitation avec paramètre' => ['/admin/ops?x=1'];
        yield 'exploitation hors dossier' => ['/admin/ops/../users'];
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
        yield 'échange identifiant zéro' => ['/exchanges/0'];
        yield 'échange avec zéros' => ['/exchanges/012'];
        yield 'échange avec paramètre' => ['/exchanges/12?x=1'];
        yield 'échange avec barre finale' => ['/exchanges/12/'];
        yield 'échange sans identifiant' => ['/exchanges/'];
        yield 'échange avec saut de ligne' => ["/exchanges/12\n"];
        yield 'échange avec séparation d\'en-tête' => ["/exchanges/12\r\nSet-Cookie: x=1"];
        yield 'échange encodé' => ['/exchanges/12%0d%0a'];
        yield 'échange trop long' => ['/exchanges/12345678901'];
        yield 'échange, sous-page' => ['/exchanges/12/respond'];
        yield 'réservations avec identifiant' => ['/admin/bookings/1'];
        yield 'réservations avec barre finale' => ['/admin/bookings/'];
        yield 'réservations avec paramètre' => ['/admin/bookings?x=1'];
        yield 'comptabilité avec paramètre' => ['/admin/accounting?year=1'];
        yield 'comptabilité, sous-page' => ['/admin/accounting/statements'];
        yield 'comptabilité, export' => ['/admin/accounting/years/1/export.csv'];
        yield 'comptabilité avec barre finale' => ['/admin/accounting/'];
        yield 'comptabilité en majuscules' => ['/ADMIN/ACCOUNTING'];
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
