<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ErrorPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorPageTest extends TestCase
{
    /** @return list<array{int, string}> */
    public static function knownStatuses(): array
    {
        return [
            [400, 'Requête incorrecte'],
            [403, 'Accès refusé'],
            [404, 'Page introuvable'],
            [405, 'Méthode non autorisée'],
            [419, 'Session expirée'],
            [423, 'Trop de requêtes'],
            [429, 'Trop de requêtes'],
            [500, 'Erreur interne'],
            [503, 'Service indisponible'],
        ];
    }

    #[Test]
    #[DataProvider('knownStatuses')]
    public function testEachStatusHasItsOwnTitleAndKeepsItsStatusCode(int $status, string $title): void
    {
        $response = ErrorPage::response($status);

        self::assertSame($status, $response->statusCode());
        self::assertStringContainsString('text/html', $response->headers()['Content-Type']);
        self::assertStringContainsString('<h1>' . $title . '</h1>', $response->body());
        self::assertStringContainsString((string) $status, $response->body(), 'le code est visible');
    }

    #[Test]
    public function testThePageIsAStyledDocumentWithABackLinkAndNoInlineScript(): void
    {
        $body = ErrorPage::response(404)->body();

        self::assertStringContainsString('<!doctype html>', $body);
        self::assertStringContainsString('<html lang="fr">', $body);
        self::assertStringContainsString('/assets/css/base.css', $body);
        self::assertStringContainsString('/assets/css/pages/error.css', $body);
        self::assertStringContainsString('href="/"', $body, 'un chemin de retour');
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('style=', $body, 'la politique de sécurité interdit le style en ligne ici');
    }

    #[Test]
    public function testAnUnknownStatusFallsBackToAGenericTitleOfItsFamily(): void
    {
        self::assertStringContainsString('<h1>Erreur du serveur</h1>', ErrorPage::response(502)->body());
        self::assertStringContainsString('<h1>Requête refusée</h1>', ErrorPage::response(418)->body());
    }

    #[Test]
    public function testTheRetryDelayIsShownOnATooManyRequestsPage(): void
    {
        $body = ErrorPage::response(429, retryAfter: 90)->body();

        self::assertStringContainsString('2 minutes', $body, 'arrondi à la minute supérieure');
        self::assertSame('90', ErrorPage::response(429, retryAfter: 90)->headers()['Retry-After']);
    }

    #[Test]
    public function testTheRetryDelayIsShownOnALockedAccountPage(): void
    {
        $body = ErrorPage::response(423, retryAfter: 90)->body();

        self::assertStringContainsString('2 minutes', $body, 'arrondi à la minute supérieure');
        self::assertSame('90', ErrorPage::response(423, retryAfter: 90)->headers()['Retry-After']);
    }

    #[Test]
    public function testNothingTechnicalEverReachesThePage(): void
    {
        $body = ErrorPage::response(500)->body();

        self::assertStringNotContainsString('Exception', $body);
        self::assertStringNotContainsString('.php', $body);
        self::assertStringNotContainsString('Stack trace', $body);
    }

    #[Test]
    public function testTheStylesheetTheErrorPageLinksToExists(): void
    {
        self::assertFileExists(__DIR__ . '/../../public/assets/css/pages/error.css');
    }
}
