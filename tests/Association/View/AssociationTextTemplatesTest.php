<?php

declare(strict_types=1);

namespace App\Tests\Association\View;

use App\Account\Entity\UserRole;
use App\Association\Presenter\AssociationTextFormatter;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #472 : gabarits admin des textes de l'association (liste et création, page d'un texte avec modification et suppression). */
final class AssociationTextTemplatesTest extends TestCase
{
    private function render(string $template, array $variables): string
    {
        return (new PhpTemplateRenderer(__DIR__ . '/../../../templates'))->render($template, $variables + [
            'csrfToken' => 'token',
            'currentUserRole' => UserRole::Admin,
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function text(array $overrides = []): array
    {
        $text = array_merge([
            'id' => 7,
            'title' => 'Statuts 2026',
            'body' => "<b>gras</b>\nligne 2",
            'revision' => 4,
            'updated' => '2026-10-10 18:30',
            'by' => 'Alice',
        ], $overrides);
        $text['bodyHtml'] = (new AssociationTextFormatter())->html($text['body']);

        return $text;
    }

    #[Test]
    public function testTheIndexLinksEachTextAndOffersACollapsedCreationPanel(): void
    {
        $html = $this->render('admin/texts/index', [
            'rows' => [['id' => 7, 'title' => 'Statuts 2026', 'updated' => '2026-10-10 18:30', 'by' => 'Alice']],
        ]);

        self::assertStringContainsString('<a href="/admin/texts/7"', $html);
        self::assertStringContainsString('Modifié le 2026-10-10 18:30 par Alice', $html);
        self::assertStringContainsString('data-action="new"', $html, 'bouton « Nouveau texte »');
        self::assertMatchesRegularExpression('/<div[^>]*data-text-create[^>]*\\bhidden\\b/', $html, 'panneau replié par défaut');
        self::assertStringContainsString('data-action="create"', $html);
        self::assertStringContainsString('data-action="cancel-create"', $html);
        self::assertStringContainsString('<p class="rb-admin-note" hidden>Aucun texte pour l\'instant.</p>', $html);
    }

    #[Test]
    public function testTheIndexSaysSoWhenThereIsNoText(): void
    {
        $html = $this->render('admin/texts/index', ['rows' => []]);

        self::assertStringContainsString('Aucun texte pour l\'instant.', $html);
        self::assertStringNotContainsString('href="/admin/texts/', $html);
    }

    #[Test]
    public function testTheIndexEscapesTheTitle(): void
    {
        $html = $this->render('admin/texts/index', [
            'rows' => [['id' => 1, 'title' => '<script>x</script>', 'updated' => '2026-10-10', 'by' => 'Alice']],
        ]);

        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
    }

    #[Test]
    public function testTheShowPageOffersAPrintButtonAndNoPrintStamp(): void
    {
        $html = $this->render('admin/texts/show', ['text' => $this->text()]);

        self::assertMatchesRegularExpression('#<div class="rb-text-view-actions">\s*<button[^>]*data-action="edit">Modifier</button>\s*<button type="button" class="rb-btn" data-action="print">Imprimer</button>\s*</div>#', $html, 'Modifier et Imprimer sur la même ligne');
        self::assertStringNotContainsString('print-stamp', $html, 'pas de « Imprimé le »');
    }

    #[Test]
    public function testTheShowPageRendersTheBodyEscapedWithLineBreaks(): void
    {
        $html = $this->render('admin/texts/show', ['text' => $this->text()]);

        self::assertStringContainsString('&lt;b&gt;gras&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>gras</b>', $html);
        self::assertStringContainsString('<p>&lt;b&gt;gras&lt;/b&gt;<br>ligne 2</p>', $html);
    }

    #[Test]
    public function testTheShowPageRendersSubtitlesFromTheirMarker(): void
    {
        $html = $this->render('admin/texts/show', ['text' => $this->text(['body' => "# Pas un titre\n## Article 1\nTexte"])]);

        self::assertStringContainsString('<p># Pas un titre</p><h2 class="rb-text-subheading">Article 1</h2><p>Texte</p>', $html);
    }

    #[Test]
    public function testBothEditorsOfferTheSubtitleAndListSwitches(): void
    {
        $show = $this->render('admin/texts/show', ['text' => $this->text()]);
        $index = $this->render('admin/texts/index', ['rows' => []]);

        foreach ([$show, $index] as $html) {
            self::assertMatchesRegularExpression('#<button type="button" class="rb-btn rb-btn-toggle" data-action="format" data-format="subtitle" aria-pressed="false">Sous-titre</button>#', $html);
            self::assertMatchesRegularExpression('#<button type="button" class="rb-btn rb-btn-toggle" data-action="format" data-format="list" aria-pressed="false">Liste</button>#', $html);
            self::assertStringNotContainsString('>Titre</button>', $html, 'le titre du texte a son propre champ');
        }
    }

    #[Test]
    public function testTheShowPageStartsInReadingModeWithTheEditPanelHidden(): void
    {
        $html = $this->render('admin/texts/show', ['text' => $this->text()]);

        self::assertStringContainsString('data-text-view', $html);
        self::assertMatchesRegularExpression('#<div [^>]*data-text-edit[^>]* hidden>#', $html);
        self::assertStringContainsString('data-action="edit"', $html);
    }

    #[Test]
    public function testTheEditPanelPrefillsEscapedValues(): void
    {
        $html = $this->render('admin/texts/show', ['text' => $this->text()]);

        self::assertStringContainsString('value="Statuts 2026"', $html);
        self::assertMatchesRegularExpression('#<textarea [^>]*name="body"[^>]*>&lt;b&gt;gras&lt;/b&gt;\nligne 2</textarea>#', $html);
        self::assertDoesNotMatchRegularExpression('#<textarea [^>]*name="body"[^>]*><b>#', $html);
    }

    #[Test]
    public function testTheShowPageExposesIdRevisionAndActions(): void
    {
        $html = $this->render('admin/texts/show', ['text' => $this->text()]);

        self::assertStringContainsString('<section data-text data-id="7" data-revision="4">', $html);
        self::assertStringContainsString('data-action="save"', $html);
        self::assertStringContainsString('data-action="delete"', $html);
        self::assertStringContainsString('data-action="cancel"', $html);
        self::assertStringContainsString('data-field-error="title"', $html);
        self::assertStringContainsString('data-field-error="body"', $html);
        self::assertStringContainsString('data-form-error', $html);
    }

    #[Test]
    public function testNoOutputContainsAnInlineStyleOrAnInlineScript(): void
    {
        $outputs = [
            $this->render('admin/texts/index', ['rows' => [['id' => 1, 'title' => 'T', 'updated' => '2026-10-10', 'by' => 'Alice']]]),
            $this->render('admin/texts/index', ['rows' => []]),
            $this->render('admin/texts/show', ['text' => $this->text()]),
        ];

        foreach ($outputs as $html) {
            self::assertStringNotContainsString('style=', $html);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        }
    }
}
