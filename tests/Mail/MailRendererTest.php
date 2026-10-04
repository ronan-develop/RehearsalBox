<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Mail\MailRenderer;
use Symfony\Component\Mime\Email;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MailRendererTest extends TestCase
{
    private MailRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = MailRenderer::withDefaultTemplates();
    }

    private const LINK = 'https://rehearsalbox.example/reset-password?token=abc123&x=1';

    // --- Gabarit commun -------------------------------------------------------------

    #[Test]
    public function testEveryMailIsAFullHtmlDocumentWithTheBrandAndNoExternalResource(): void
    {
        foreach ([
            ['password-reset', ['link' => self::LINK]],
            ['account-alert', ['link' => self::LINK]],
        ] as [$template, $data]) {
            $html = $this->renderer->render($template, $data)['html'];

            self::assertStringStartsWith('<!doctype html>', $html, $template);
            self::assertStringContainsString('lang="fr"', $html);
            self::assertStringContainsString('RehearsalBox', $html);
            self::assertStringContainsString('#B27', $html, 'marque du site');
            self::assertStringContainsString('#b5654a', $html, "couleur d'accent du site");
            self::assertStringContainsString('role="presentation"', $html, 'mise en page en tables (clients de messagerie)');
            // Aucune ressource distante ni script : rien ne se charge à l'ouverture.
            self::assertStringNotContainsString('<script', $html);
            self::assertDoesNotMatchRegularExpression('/(src|href)="http[^"]*\.(png|jpg|gif|svg|css|js|woff2?)"/i', $html);
            self::assertStringNotContainsString('@import', $html);
            self::assertStringNotContainsString('<link', $html);
        }
    }

    #[Test]
    public function testEveryMailHasABothHtmlAndPlainTextVersion(): void
    {
        foreach (['password-reset', 'account-alert'] as $template) {
            $mail = $this->renderer->render($template, ['link' => self::LINK]);

            self::assertNotSame('', trim($mail['html']), $template);
            self::assertNotSame('', trim($mail['text']), $template);
            self::assertStringNotContainsString('<', $mail['text'], 'la version texte ne contient pas de HTML');
        }
    }

    // --- Réinitialisation ----------------------------------------------------------------

    #[Test]
    public function testPasswordResetCarriesTheLinkInTheButtonAndAsAFallbackInBothVersions(): void
    {
        $mail = $this->renderer->render('password-reset', ['link' => self::LINK]);

        // Le « & » du lien est échappé dans l'attribut HTML, intact dans la version texte.
        self::assertStringContainsString('href="https://rehearsalbox.example/reset-password?token=abc123&amp;x=1"', $mail['html']);
        self::assertStringContainsString('Choisir un nouveau mot de passe', $mail['html']);
        self::assertStringContainsString(self::LINK, $mail['text']);
        foreach ([$mail['html'], $mail['text']] as $body) {
            self::assertStringContainsString('1 heure', $body);
            self::assertStringContainsString('usage unique', $body);
            self::assertStringContainsString("Si vous n'êtes pas à l'origine de cette demande", $body);
        }
    }

    #[Test]
    public function testPasswordResetEscapesAMaliciousLink(): void
    {
        $html = $this->renderer->render('password-reset', ['link' => 'https://x.test/"><script>alert(1)</script>'])['html'];

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // --- Alerte « Ce n'est pas moi » --------------------------------------------------------

    #[Test]
    public function testAccountAlertExplainsWhatHappensAndLinksToSecureTheAccount(): void
    {
        $mail = $this->renderer->render('account-alert', ['link' => self::LINK]);

        foreach ([$mail['html'], $mail['text']] as $body) {
            self::assertStringContainsString('vient d\'être modifié', $body);
            self::assertStringContainsString('24 heures', $body);
            self::assertStringContainsString('verrouillé', $body);
        }
        self::assertStringContainsString('href="https://rehearsalbox.example/reset-password?token=abc123&amp;x=1"', $mail['html']);
        self::assertStringContainsString(self::LINK, $mail['text']);
    }

    // --- Logo du site (#159) -------------------------------------------------------------------

    #[Test]
    public function testHeaderShowsTheSiteLogoAsAnEmbeddedImageWithATextFallback(): void
    {
        $html = $this->renderer->render('password-reset', ['link' => self::LINK])['html'];

        self::assertStringContainsString('<img src="cid:logo-b27"', $html);
        self::assertStringContainsString('alt="#B27 RehearsalBox"', $html);
        self::assertStringContainsString('width="200"', $html);
        self::assertStringContainsString('Local</div>', $html, 'le mot « Local » sous le logo (mis en capitales par le style), comme sur la page de connexion');
    }

    #[Test]
    public function testTheLogoAssetIsATransparentPngOfReasonableSize(): void
    {
        $path = __DIR__ . '/../../public/assets/img/mail-logo-b27.png';

        self::assertFileExists($path);
        self::assertSame("\x89PNG", substr((string) file_get_contents($path, false, null, 0, 4), 0, 4));
        self::assertLessThan(80 * 1024, filesize($path), 'image légère pour un e-mail');
        [$width, $height] = getimagesize($path);
        self::assertSame([402, 204], [$width, $height], 'affichée à 200 px de large : nette sur écran 2x');
    }

    #[Test]
    public function testComposeEmbedsTheLogoAsAnInlinePartAndSetsBothBodies(): void
    {
        $email = $this->renderer->compose(
            (new Email())->from('no-reply@example.test')->to('a@example.test')->subject('Sujet'),
            'password-reset',
            ['link' => self::LINK],
        );

        self::assertStringContainsString('Choisir un nouveau mot de passe', (string) $email->getHtmlBody());
        self::assertStringContainsString(self::LINK, (string) $email->getTextBody());
        $parts = $email->getAttachments();
        self::assertCount(1, $parts, 'une seule pièce : le logo');
        self::assertSame('image/png', $parts[0]->getMediaType() . '/' . $parts[0]->getMediaSubtype());
        self::assertStringContainsString('inline', $parts[0]->getPreparedHeaders()->get('Content-Disposition')->getBodyAsString());
        self::assertSame("\x89PNG", substr($parts[0]->getBody(), 0, 4));
    }

    // --- Changement d'adresse e-mail (#164) ---------------------------------------------------------

    #[Test]
    public function testEmailChangeAsksTheNewAddressToConfirmWithAOneHourSingleUseLink(): void
    {
        $mail = $this->renderer->render('email-change', ['link' => self::LINK]);

        self::assertStringContainsString('href="https://rehearsalbox.example/reset-password?token=abc123&amp;x=1"', $mail['html']);
        self::assertStringContainsString('Confirmer ma nouvelle adresse', $mail['html']);
        self::assertStringContainsString(self::LINK, $mail['text']);
        foreach ([$mail['html'], $mail['text']] as $body) {
            self::assertStringContainsString('1 heure', $body);
            self::assertStringContainsString('usage unique', $body);
            self::assertStringContainsString("Si vous n'êtes pas à l'origine de cette demande", $body);
        }
    }

    #[Test]
    public function testEmailChangeEscapesAMaliciousLink(): void
    {
        $html = $this->renderer->render('email-change', ['link' => 'https://x.test/"><script>alert(1)</script>'])['html'];

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    #[Test]
    public function testEmailChangedAlertTellsTheOldAddressAboutTheChangeWithoutAnyLink(): void
    {
        $mail = $this->renderer->render('email-changed', ['newEmailMasked' => 'n***@exemple.test']);

        foreach ([$mail['html'], $mail['text']] as $body) {
            self::assertStringContainsString('n***@exemple.test', $body);
            self::assertStringContainsString('a été modifiée', $body);
            self::assertStringContainsString('administrateur', $body, "recours si ce n'est pas la personne : un administrateur");
            self::assertStringNotContainsString('token=', $body, "aucun jeton ni lien d'action dans cette alerte");
        }
        self::assertStringNotContainsString('<a href', $mail['html'], "aucun lien d'action");
    }

    #[Test]
    public function testEmailChangedAlertEscapesTheMaskedAddress(): void
    {
        $html = $this->renderer->render('email-changed', ['newEmailMasked' => '"><img src=x onerror=alert(1)>'])['html'];

        self::assertStringNotContainsString('<img src=x', $html);
    }
}
