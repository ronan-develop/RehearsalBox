<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #404 : chaque page qui n'est pas une racine (disponibilités, connexion) offre un moyen de revenir en arrière sans le bouton du
 * navigateur. Le bouton est le composant commun `partials/back-button.php` (.rb-back-link, une seule icône) ; les pages d'authentification
 * gardent leur lien texte « Retour à la connexion ». On lit les gabarits eux-mêmes : une page ajoutée sans retour fait échouer ce test.
 */
final class BackButtonTemplatesTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../templates/';

    /** @return iterable<string, array{0: string, 1: string}> gabarit => destination du retour */
    public static function pagesWithBackButton(): iterable
    {
        yield 'mon compte' => ['account/password.php', '/'];
        yield 'groupes' => ['admin/groups/index.php', '/'];
        yield 'utilisateurs' => ['admin/users/index.php', '/'];
        yield 'réservations' => ['admin/bookings/index.php', '/'];
        yield 'assemblées' => ['admin/assemblies/index.php', '/'];
        yield 'assemblée (page)' => ['admin/assemblies/edit.php', '/admin/assemblies'];
        yield 'créneaux' => ['admin/slots/index.php', '/'];
        yield 'mesures (en-tête)' => ['admin/metrics/_header.php', '/admin/ops'];
        yield 'exploitation (en-tête)' => ['admin/ops/_header.php', '/'];
        yield 'restauration' => ['admin/restore/index.php', '/admin/ops'];
    }

    #[Test]
    #[DataProvider('pagesWithBackButton')]
    public function testThePageUsesTheCommonBackButtonToItsParent(string $template, string $destination): void
    {
        $source = (string) file_get_contents(self::TEMPLATES . $template);

        self::assertMatchesRegularExpression(
            '#\$backHref = \'' . preg_quote($destination, '#') . '\';.*partials/back-button\.php#s',
            $source,
            $template . ' doit afficher le bouton retour vers ' . $destination,
        );
    }

    #[Test]
    public function testTheCommonButtonIsAnAccessibleLinkWithTheSharedIcon(): void
    {
        $backHref = '/admin/ops';
        $backLabel = 'Retour à l\'exploitation';
        ob_start();
        require self::TEMPLATES . 'partials/back-button.php';
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<a href="/admin/ops" class="rb-back-link" aria-label="Retour à l&#039;exploitation">', $html);
        self::assertStringContainsString('<svg', $html, 'la même icône que les autres retours');
    }

    #[Test]
    public function testTheResetPasswordPageOffersTheWayBackToTheLogin(): void
    {
        $source = (string) file_get_contents(self::TEMPLATES . 'auth/reset-password.php');

        self::assertGreaterThanOrEqual(2, substr_count($source, 'Retour à la connexion'), 'lien valide ET lien invalide');
    }

    /** @return iterable<string, array{0: string}> */
    public static function authPagesWithTextLink(): iterable
    {
        foreach (['auth/forgot-password.php', 'auth/secure-account.php', 'auth/confirm-email.php'] as $template) {
            yield $template => [$template];
        }
    }

    #[Test]
    #[DataProvider('authPagesWithTextLink')]
    public function testTheOtherAuthenticationPagesKeepTheirBackToLoginLink(string $template): void
    {
        self::assertStringContainsString('href="/login">Retour à la connexion', (string) file_get_contents(self::TEMPLATES . $template));
    }

    #[Test]
    public function testTheErrorPageOffersTheWayHome(): void
    {
        self::assertStringContainsString('Retour à l’accueil', (string) file_get_contents(self::TEMPLATES . 'errors/error.php'));
    }
}
