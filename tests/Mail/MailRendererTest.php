<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Mail\MailRenderer;
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
            ['group-contact', ['senderEmail' => 'musicien@example.test', 'message' => 'Bonjour']],
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

    // --- Contact entre groupes --------------------------------------------------------------

    #[Test]
    public function testGroupContactEscapesTheUserMessageAndKeepsLineBreaks(): void
    {
        $mail = $this->renderer->render('group-contact', [
            'senderEmail' => 'musicien@example.test',
            'message' => "Salut <script>alert(1)</script>\nOn répète mardi ?",
        ]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $mail['html']);
        self::assertStringContainsString('&lt;script&gt;', $mail['html']);
        self::assertStringContainsString('<br', $mail['html'], 'les sauts de ligne sont conservés');
        self::assertStringContainsString('musicien@example.test', $mail['html']);
        // Version texte : le message tel qu'écrit, sans HTML ajouté.
        self::assertStringContainsString("Salut <script>alert(1)</script>\nOn répète mardi ?", $mail['text']);
    }

    #[Test]
    public function testGroupContactEscapesTheSenderEmail(): void
    {
        $html = $this->renderer->render('group-contact', ['senderEmail' => '"><img src=x onerror=alert(1)>@x.test', 'message' => 'a'])['html'];

        self::assertStringNotContainsString('<img src=x', $html);
    }
}
