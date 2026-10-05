<?php

declare(strict_types=1);

namespace App\Tests\Presenter;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Entity\ConversationThread;
use App\Entity\Group;
use App\Entity\SeenReceipt;
use App\Presenter\ConversationPresenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationPresenterTest extends TestCase
{
    private function message(int $id, int $authorId, string $author, string $body, bool $system = false): ConversationMessage
    {
        return new ConversationMessage($id, 7, $authorId, $author, $body, new \DateTimeImmutable('2026-10-04 12:00:00'), $system);
    }

    #[Test]
    public function testMessageCarriesAuthorBadgeMineAndSystemFlags(): void
    {
        $presenter = new ConversationPresenter();
        $group = new Group(3, 'Alpha', null, '#aa0000', 'a@example.test');

        $mine = $presenter->message($this->message(1, 10, 'Alice Martin', 'Salut'), $group, 10);
        $theirs = $presenter->message($this->message(2, 11, 'Bob', 'Hello', false), null, 10);
        $system = $presenter->message($this->message(3, 11, 'Bob', 'a renommé la conversation « X »', true), null, 10);

        self::assertTrue($mine['mine']);
        self::assertSame('AM', $mine['initials']);
        self::assertSame('Alpha', $mine['groupName']);
        self::assertSame('#aa0000', $mine['groupColor']);
        self::assertFalse($theirs['mine']);
        self::assertSame('BO', $theirs['initials']);
        self::assertNull($theirs['groupName'], 'auteur ambigu : pastille neutre');
        self::assertNull($theirs['groupColor']);
        self::assertTrue($system['system']);
        self::assertSame('2026-10-04T12:00:00+00:00', $mine['createdAt']);
        self::assertArrayNotHasKey('authorId', $mine, 'aucun identifiant interne inutile côté client');
    }

    #[Test]
    public function testThreadExposesTitleLabelTypingAndSeen(): void
    {
        $conversation = new Conversation(7, 3, 4, null, new \DateTimeImmutable('2026-10-04 11:00:00'));
        $thread = new ConversationThread($conversation, 'Alpha ↔ Beta', [$this->message(1, 10, 'Alice', 'Salut')], ['Bob'], new SeenReceipt(1, ['Bob'], 3), []);

        $json = (new ConversationPresenter())->thread($thread, 10);

        self::assertSame(7, $json['id']);
        self::assertNull($json['title']);
        self::assertSame('Alpha ↔ Beta', $json['displayTitle']);
        self::assertSame('Alpha ↔ Beta', $json['label']);
        self::assertSame(['Bob'], $json['typing']);
        self::assertSame(['messageId' => 1, 'names' => ['Bob'], 'total' => 3], $json['seen']);
        self::assertCount(1, $json['messages']);
    }

    #[Test]
    public function testSummaryHasTheDisplayTitleLastMessageAndUnreadFlag(): void
    {
        $conversation = new Conversation(7, 3, 4, 'Concert', new \DateTimeImmutable('2026-10-04 11:00:00'));
        $summary = new ConversationSummary($conversation, 'Alpha', 'Beta', $this->message(5, 11, 'Bob', 'Dernier'), true);

        $json = (new ConversationPresenter())->summary($summary, 10);

        self::assertSame('Concert', $json['displayTitle']);
        self::assertSame('Alpha ↔ Beta', $json['label']);
        self::assertTrue($json['unread']);
        self::assertSame('Dernier', $json['lastMessage']['body']);
        self::assertFalse($json['lastMessage']['mine']);
    }
}
