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
        self::assertStringNotContainsString('title=', $html);
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
}
