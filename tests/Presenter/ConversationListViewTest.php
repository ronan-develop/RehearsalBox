<?php

declare(strict_types=1);

namespace App\Tests\Presenter;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Presenter\ConversationFormatter;
use App\Presenter\ConversationListView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationListViewTest extends TestCase
{
    private function summary(int $id, ?string $title, bool $unread, string $body, bool $mine = false, string $at = '2026-10-04 07:05:00'): ConversationSummary
    {
        $conversation = new Conversation($id, 3, 4, $title, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $message = new ConversationMessage(50 + $id, $id, $mine ? 10 : 11, $mine ? 'Alice' : 'Bob', $body, new \DateTimeImmutable($at, new \DateTimeZone('UTC')));

        return new ConversationSummary($conversation, 'Alpha', 'Beta', $message, $unread);
    }

    #[Test]
    public function testItemsCarryLinkTitleDatePreviewUnreadAndActiveFlags(): void
    {
        $view = new ConversationListView(new ConversationFormatter(new \DateTimeZone('Europe/Paris')));
        $now = new \DateTimeImmutable('2026-10-04 16:00:00', new \DateTimeZone('UTC'));

        $items = $view->items([
            $this->summary(7, 'Concert du 12', true, 'Salut', false),
            $this->summary(8, null, false, 'Réponse', true, '2026-10-03 07:05:00'),
        ], 10, $now, 8);

        self::assertSame('/messages/7', $items[0]['url']);
        self::assertSame('Concert du 12', $items[0]['title']);
        self::assertSame('09:05', $items[0]['date']);
        self::assertSame('Bob : Salut', $items[0]['preview']);
        self::assertTrue($items[0]['unread']);
        self::assertFalse($items[0]['active']);
        self::assertSame('Alpha ↔ Beta', $items[1]['title'], 'sans titre : label des deux groupes');
        self::assertSame('Hier', $items[1]['date']);
        self::assertSame('Vous : Réponse', $items[1]['preview']);
        self::assertTrue($items[1]['active']);
    }

    #[Test]
    public function testNoConversationGivesNoItem(): void
    {
        $view = new ConversationListView(new ConversationFormatter(new \DateTimeZone('UTC')));

        self::assertSame([], $view->items([], 10, new \DateTimeImmutable(), null));
    }
}
