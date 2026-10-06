<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #183 : le serveur dessine les messages et la liste ; tout contenu d'utilisateur est échappé par e(). */
final class MessageRowsTemplateTest extends TestCase
{
    private PhpTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PhpTemplateRenderer(__DIR__ . '/../../templates');
    }

    /** @param array<string, mixed> $override */
    private function message(array $override = []): array
    {
        return $override + [
            'type' => 'message', 'id' => 5, 'mine' => false, 'startsRun' => true, 'author' => 'Bob', 'initials' => 'BO',
            'groupName' => 'Alpha', 'color' => '#aa0000', 'body' => 'Salut', 'time' => '09:05',
        ];
    }

    private function rows(array $rows): string
    {
        return $this->renderer->render('messages/_rows', ['rows' => $rows]);
    }

    #[Test]
    public function testMentionsAreHighlightedAndEveryPartIsEscaped(): void
    {
        $html = $this->rows([$this->message(['segments' => [
            ['text' => '<b>Salut ', 'mention' => false, 'me' => false],
            ['text' => '@Denis', 'mention' => true, 'me' => true],
            ['text' => '</b>', 'mention' => false, 'me' => false],
        ], 'mentionsMe' => true])]);

        self::assertStringContainsString('<span class="rb-chat-mention rb-chat-mention--me">@Denis</span>', $html);
        self::assertStringContainsString('&lt;b&gt;Salut ', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('rb-chat-message--mentioned', $html, 'la bulle de la personne taguée est surlignée');
    }

    #[Test]
    public function testAnEditedMessageShowsTheEditMarkAndAnEditableOneIsFlagged(): void
    {
        $html = $this->rows([$this->message(['mine' => true, 'edited' => '14:05', 'editable' => true])]);

        self::assertStringContainsString('data-editable', $html);
        self::assertMatchesRegularExpression('/<span class="rb-chat-edited">modifié à 14:05<\/span>/', $html);
        self::assertStringNotContainsString('09:05', $html, 'l\'heure de la correction remplace l\'heure d\'envoi');
        self::assertStringContainsString('data-message-body', $html, 'zone remplaçable par le polling quand le texte est corrigé');

        $plain = $this->rows([$this->message()]);
        self::assertStringNotContainsString('data-editable', $plain);
        self::assertStringNotContainsString('rb-chat-edited', $plain);
    }

    #[Test]
    public function testTheMessageBodyPartialIsWhatTheFragmentsReplace(): void
    {
        $html = $this->renderer->render('messages/_message-body', ['row' => $this->message(['edited' => '14:05', 'body' => '<i>x</i>', 'segments' => [['text' => '<i>x</i>', 'mention' => false, 'me' => false]]])]);

        self::assertStringContainsString('&lt;i&gt;x&lt;/i&gt;', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringContainsString('rb-chat-edited', $html);
        self::assertStringContainsString('rb-chat-time', $html);
    }

    #[Test]
    public function testARowWithoutSegmentsStillShowsItsBody(): void
    {
        self::assertStringContainsString('<p class="rb-chat-text">Salut</p>', $this->rows([$this->message()]));
    }

    #[Test]
    public function testOthersMessageShowsBadgeAuthorBodyTimeAndAnIdForIncrementalUpdates(): void
    {
        $html = $this->rows([$this->message()]);

        self::assertStringContainsString('data-message-id="5"', $html);
        self::assertStringContainsString('rb-chat-avatar', $html);
        self::assertStringContainsString('>BO<', $html);
        self::assertStringContainsString('--group-color: #aa0000', $html);
        self::assertStringContainsString('title="Alpha"', $html);
        self::assertStringContainsString('rb-chat-author', $html);
        self::assertStringContainsString('>Bob<', $html);
        self::assertStringContainsString('Salut', $html);
        self::assertStringContainsString('09:05', $html);
        self::assertStringNotContainsString('rb-chat-message--mine', $html);
    }

    #[Test]
    public function testMyMessageIsOnTheRightWithoutBadgeNorAuthor(): void
    {
        $html = $this->rows([$this->message(['mine' => true, 'author' => 'Alice'])]);

        self::assertStringContainsString('rb-chat-message--mine', $html);
        self::assertStringNotContainsString('rb-chat-avatar"', $html);
        self::assertStringNotContainsString('rb-chat-author', $html);
    }

    #[Test]
    public function testAContinuedRunKeepsAnAlignmentSpacerInsteadOfAnotherBadge(): void
    {
        $html = $this->rows([$this->message(['startsRun' => false])]);

        self::assertStringContainsString('rb-chat-avatar-spacer', $html);
        self::assertStringNotContainsString('rb-chat-author', $html);
        self::assertStringNotContainsString('>BO<', $html);
    }

    #[Test]
    public function testNoColourMeansNoStyleAttribute(): void
    {
        $html = $this->rows([$this->message(['color' => null, 'groupName' => null])]);

        self::assertStringNotContainsString('style=', $html);
        self::assertDoesNotMatchRegularExpression('/rb-chat-avatar[^>]*title=/', $html, 'aucune infobulle de groupe sur la pastille');
    }

    #[Test]
    public function testDayUnreadAndSystemRowsAreDrawnAsSeparators(): void
    {
        $html = $this->rows([['type' => 'day', 'label' => 'Hier'], ['type' => 'unread'], ['type' => 'system', 'text' => 'Bob a renommé la conversation']]);

        self::assertStringContainsString('<li class="rb-chat-day">Hier</li>', $html);
        self::assertStringContainsString('rb-chat-unread', $html);
        self::assertStringContainsString('Messages non lus', $html);
        self::assertStringContainsString('<li class="rb-chat-system">Bob a renommé la conversation</li>', $html);
    }

    #[Test]
    public function testEverythingAUserCanWriteIsEscaped(): void
    {
        $evil = '<script>alert(1)</script>';
        $html = $this->rows([
            $this->message(['body' => $evil, 'author' => $evil, 'groupName' => '"><img src=x onerror=alert(1)>', 'initials' => $evil]),
            ['type' => 'system', 'text' => $evil],
            ['type' => 'day', 'label' => $evil],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function testNoRowsGivesNoMarkup(): void
    {
        self::assertSame('', trim($this->rows([])));
    }

    #[Test]
    public function testOnlyDeletableConversationsGetADeleteButtonOutsideTheLinkWithAnEscapedAccessibleName(): void
    {
        $html = $this->renderer->render('messages/_conversation-items', ['items' => [
            ['id' => 7, 'url' => '/messages/7', 'title' => '<b>Concert</b>', 'date' => '09:05', 'preview' => 'x', 'unread' => false, 'active' => false, 'canDelete' => true],
            ['id' => 8, 'url' => '/messages/8', 'title' => 'Fil', 'date' => 'Hier', 'preview' => 'y', 'unread' => false, 'active' => false, 'canDelete' => false],
        ]]);

        self::assertSame(1, substr_count($html, 'data-trash-action="delete"'));
        self::assertSame(1, substr_count($html, 'data-can-delete'));
        self::assertMatchesRegularExpression('/<button[^>]*class="rb-chat-item-delete"[^>]*data-id="7"/', $html);
        self::assertStringContainsString('aria-label="Supprimer la conversation &lt;b&gt;Concert&lt;/b&gt;"', $html);
        self::assertDoesNotMatchRegularExpression('#<a [^>]*>[^<]*(<span[^>]*>.*?</span>\s*)*<button#s', $html, 'le bouton n\'est pas dans le lien');
    }

    #[Test]
    public function testEveryRowHasAMuteToggleOutsideTheLinkWithItsStateAndAnEscapedAccessibleName(): void
    {
        $html = $this->renderer->render('messages/_conversation-items', ['items' => [
            ['id' => 7, 'url' => '/messages/7', 'title' => '<b>Concert</b>', 'date' => '09:05', 'preview' => 'x', 'unread' => false, 'active' => false, 'muted' => false],
            ['id' => 8, 'url' => '/messages/8', 'title' => 'Fil', 'date' => 'Hier', 'preview' => 'y', 'unread' => false, 'active' => false, 'muted' => true],
        ]]);

        self::assertSame(2, substr_count($html, '<rb-mute-toggle'), 'un interrupteur par conversation, quel que soit le droit de suppression');
        self::assertMatchesRegularExpression('/<rb-mute-toggle[^>]*data-id="7"[^>]*>\s*<button[^>]*aria-pressed="false"/', $html);
        self::assertMatchesRegularExpression('/<rb-mute-toggle[^>]*data-id="8"[^>]*>\s*<button[^>]*aria-pressed="true"/', $html);
        self::assertStringContainsString('aria-label="Mettre en sourdine : &lt;b&gt;Concert&lt;/b&gt;"', $html);
        self::assertStringContainsString('aria-label="Réactiver les notifications : Fil"', $html);
        self::assertSame(1, substr_count($html, 'rb-chat-item--muted'), 'la ligne en sourdine est marquée');
        self::assertDoesNotMatchRegularExpression('#<a [^>]*>[^<]*(<span[^>]*>.*?</span>\s*)*<rb-mute-toggle#s', $html, 'hors du lien');
    }

    #[Test]
    public function testConversationItemsAreRealLinksWithUnreadAndActiveStatesAndEscapedText(): void
    {
        $html = $this->renderer->render('messages/_conversation-items', ['items' => [
            ['id' => 7, 'url' => '/messages/7', 'title' => '<b>Concert</b>', 'date' => '09:05', 'preview' => 'Bob : <i>Salut</i>', 'unread' => true, 'active' => false],
            ['id' => 8, 'url' => '/messages/8', 'title' => 'Fil', 'date' => 'Hier', 'preview' => 'Vous : ok', 'unread' => false, 'active' => true],
        ]]);

        self::assertStringContainsString('href="/messages/7"', $html);
        self::assertStringContainsString('href="/messages/8"', $html);
        self::assertStringContainsString('rb-chat-item--unread', $html);
        self::assertStringContainsString('rb-chat-item--active', $html);
        self::assertStringContainsString('rb-chat-item-dot', $html);
        self::assertStringNotContainsString('<b>Concert</b>', $html);
        self::assertStringContainsString('&lt;b&gt;Concert&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<i>Salut</i>', $html);
    }

    #[Test]
    public function testAQuoteIsDrawnAboveTheTextWithItsAuthorAndAJumpLinkAndEverythingIsEscaped(): void
    {
        $html = $this->rows([$this->message(['quote' => ['id' => 7, 'author' => 'Bob <i>', 'excerpt' => 'Jeudi & "vendredi" <script>x</script>']])]);

        self::assertStringContainsString('<a class="rb-chat-quote" href="#message-7">', $html);
        self::assertStringContainsString('Bob &lt;i&gt;', $html);
        self::assertStringContainsString('Jeudi &amp; &quot;vendredi&quot; &lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertLessThan(strpos($html, 'rb-chat-text'), strpos($html, 'rb-chat-quote"'), 'la citation précède le texte');
    }

    #[Test]
    public function testNoQuoteMeansNoQuoteBlock(): void
    {
        $html = $this->rows([$this->message(['quote' => null])]);

        self::assertStringNotContainsString('class="rb-chat-quote"', $html);
    }

    #[Test]
    public function testEveryMessageCanBeAnsweredButSeparatorsAndSystemLinesCannot(): void
    {
        $html = $this->rows([
            ['type' => 'day', 'label' => 'Hier'],
            $this->message(['id' => 4, 'author' => 'Bob']),
            ['type' => 'system', 'text' => 'Bob a renommé la conversation'],
        ]);

        self::assertSame(1, substr_count($html, 'data-quote-message'), 'le message seulement');
        self::assertStringContainsString('data-author="Bob"', $html);
        self::assertStringContainsString('id="message-4"', $html, 'cible du lien de la citation');
    }
}
