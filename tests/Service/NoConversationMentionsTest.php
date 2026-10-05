<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Service\Contract\ConversationMentionsInterface;
use App\Service\NoConversationMentions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Null Object : une messagerie sans mentions (tests, configuration minimale) ne branche aucune logique conditionnelle. */
final class NoConversationMentionsTest extends TestCase
{
    #[Test]
    public function testItIsAConversationMentionsCollaborator(): void
    {
        self::assertInstanceOf(ConversationMentionsInterface::class, new NoConversationMentions());
    }

    #[Test]
    public function testEveryPlanIsEmptyWhateverTheTextAndTheIdentifiers(): void
    {
        $mentions = new NoConversationMentions();
        $conversation = new Conversation(1, 1, 2, null, new \DateTimeImmutable());

        $plan = $mentions->plan(1, 1, 2, null, 'Salut @Denis', [3, 4]);
        $edit = $mentions->planEdit(1, $conversation, 5, 'Salut @Denis', [3]);

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->outsiders());
        self::assertTrue($edit->isEmpty());
    }

    #[Test]
    public function testItNeverInvitesNorNotifiesNorRecordsAndShowsNoMentions(): void
    {
        $mentions = new NoConversationMentions();
        $conversation = new Conversation(1, 1, 2, null, new \DateTimeImmutable());
        $plan = $mentions->plan(1, 1, 2, null, 'Salut', []);
        $message = new ConversationMessage(9, 1, 1, 'Alice', 'Salut', new \DateTimeImmutable(), false);

        $mentions->addGuests($plan, 1, 1, new \DateTimeImmutable());
        $mentions->record($plan, 9);
        $mentions->replace($plan, 9);
        $mentions->notify($plan, $conversation, 1, 'Alice', new \DateTimeImmutable());

        self::assertSame([], $mentions->forMessages([$message]));
    }
}
