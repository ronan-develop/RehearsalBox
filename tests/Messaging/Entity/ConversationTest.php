<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Entity;

use App\Messaging\Entity\Conversation;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConversationTest extends TestCase
{
    private function group(): Conversation
    {
        return new Conversation(1, 10, 20, null, new \DateTimeImmutable('2026-10-04'));
    }

    private function direct(): Conversation
    {
        return new Conversation(2, null, null, null, new \DateTimeImmutable('2026-10-04'), 7, null, 7, 9);
    }

    #[Test]
    public function testAGroupConversationIsNotDirect(): void
    {
        self::assertFalse($this->group()->isDirect());
        self::assertSame(10, $this->group()->initiatorGroupId());
        self::assertNull($this->group()->otherParticipantOf(7));
    }

    #[Test]
    public function testADirectConversationHasNoGroupAndTwoPeople(): void
    {
        $conversation = $this->direct();

        self::assertTrue($conversation->isDirect());
        self::assertNull($conversation->initiatorGroupId());
        self::assertNull($conversation->targetGroupId());
        self::assertTrue($conversation->hasDirectParticipant(7));
        self::assertTrue($conversation->hasDirectParticipant(9));
        self::assertFalse($conversation->hasDirectParticipant(8));
    }

    #[Test]
    public function testTheOtherParticipantIsTheOtherPerson(): void
    {
        self::assertSame(9, $this->direct()->otherParticipantOf(7));
        self::assertSame(7, $this->direct()->otherParticipantOf(9));
        self::assertNull($this->direct()->otherParticipantOf(8));
    }
}
