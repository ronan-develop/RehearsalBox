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
            ['conversation-new', ['authorName' => 'Alice', 'groupName' => 'Alpha', 'link' => self::LINK]],
            ['conversation-reminder', ['counterpartName' => 'Alpha', 'link' => self::LINK]],
            ['mention-new', ['mentionerName' => 'Alice', 'link' => self::LINK, 'accountLink' => self::LINK]],
            ['mention-reminder', ['mentionerName' => 'Alice', 'link' => self::LINK, 'accountLink' => self::LINK]],
            ['booking-pending', ['groupName' => 'Alpha', 'when' => 'mercredi 7 octobre 2026', 'range' => '09:00 – 14:00', 'link' => self::LINK]],
            ['booking-decided', ['groupName' => 'Alpha', 'when' => 'mercredi 7 octobre 2026', 'range' => '09:00 – 14:00', 'accepted' => true, 'note' => null, 'link' => self::LINK]],
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

    // --- E-mails de mention (#178) --------------------------------------------------------------------

    #[Test]
    public function testMentionEmailsNameTheAuthorLinkToTheConversationAndToTheUnsubscribeAndEscapeEverything(): void
    {
        foreach (['mention-new', 'mention-reminder'] as $template) {
            $mail = $this->renderer->render($template, ['mentionerName' => '<b>Alice</b>', 'link' => self::LINK, 'accountLink' => 'https://rehearsalbox.example/account/password']);

            self::assertStringContainsString('&lt;b&gt;Alice&lt;/b&gt;', $mail['html'], $template);
            self::assertStringNotContainsString('<b>Alice</b>', $mail['html'], $template);
            self::assertStringContainsString('href="https://rehearsalbox.example/reset-password?token=abc123&amp;x=1"', $mail['html'], $template);
            self::assertStringContainsString(self::LINK, $mail['text'], $template);
            foreach ([$mail['html'], $mail['text']] as $body) {
                self::assertStringContainsString('https://rehearsalbox.example/account/password', $body, $template . ' : lien de désinscription');
                self::assertStringContainsString('se lit sur le site', $body, $template . ' : le contenu n\'est pas envoyé');
            }
        }
    }

    #[Test]
    public function testTheBookingToValidateNamesTheGroupTheDayAndTheRangeWithoutAnyUnsubscribeNorReason(): void
    {
        $mail = $this->renderer->render('booking-pending', ['groupName' => '<b>Alpha</b>', 'when' => 'mercredi 7 octobre 2026', 'range' => '09:00 – 14:00', 'link' => self::LINK]);

        self::assertStringContainsString(htmlspecialchars(self::LINK), $mail['html'], 'le lien est échappé en HTML');
        self::assertStringContainsString(self::LINK, $mail['text']);
        foreach ([$mail['html'], $mail['text']] as $body) {
            self::assertStringContainsString('mercredi 7 octobre 2026', $body);
            self::assertStringContainsString('09:00 – 14:00', $body);
            self::assertStringNotContainsStringIgnoringCase('désinscri', $body, 'alerte de gestion : non désactivable');
            self::assertStringNotContainsStringIgnoringCase('notifications dans Mon compte', $body);
        }
        self::assertStringContainsString('&lt;b&gt;Alpha&lt;/b&gt;', $mail['html']);
        self::assertStringNotContainsString('<b>Alpha</b>', $mail['html']);
    }

    #[Test]
    public function testTheDecisionMailSaysValidatedOrRefusedAndShowsTheEscapedOptionalNote(): void
    {
        $data = ['groupName' => 'Alpha', 'when' => 'mercredi 7 octobre 2026', 'range' => '09:00 – 14:00', 'link' => self::LINK];

        $accepted = $this->renderer->render('booking-decided', $data + ['accepted' => true, 'note' => null]);
        foreach ([$accepted['html'], $accepted['text']] as $body) {
            self::assertStringContainsStringIgnoringCase('validée', $body);
            self::assertStringNotContainsStringIgnoringCase('refusée', $body);
        }

        $refused = $this->renderer->render('booking-decided', $data + ['accepted' => false, 'note' => 'Local fermé <script>x</script>']);
        foreach ([$refused['html'], $refused['text']] as $body) {
            self::assertStringContainsStringIgnoringCase('refusée', $body);
            self::assertStringContainsString('Local fermé', $body);
        }
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $refused['html']);
        self::assertStringNotContainsString('<script>', $refused['html']);

        $withoutNote = $this->renderer->render('booking-decided', $data + ['accepted' => false, 'note' => null]);
        self::assertStringNotContainsStringIgnoringCase('motif', $withoutNote['html'], 'sans motif, rien n\'est inventé');
    }

    #[Test]
    public function testTheMentionReminderSaysTheMentionIsStillUnread(): void
    {
        $mail = $this->renderer->render('mention-reminder', ['mentionerName' => 'Alice', 'link' => self::LINK, 'accountLink' => self::LINK]);

        foreach ([$mail['html'], $mail['text']] as $body) {
            self::assertStringContainsString('pas encore', $body);
        }
    }
}
