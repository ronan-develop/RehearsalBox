<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Conversation;
use App\Group\Entity\Group;
use App\Service\Contract\NewConversationNotifierInterface;
use App\Service\NoNewConversationNotice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Null Object : sans e-mail de nouvelle conversation, l'appelant n'a aucune branche « notificateur absent » à écrire. */
final class NoNewConversationNoticeTest extends TestCase
{
    #[Test]
    public function testItDoesNothingAndNeverFails(): void
    {
        $notifier = new NoNewConversationNotice();
        $now = new \DateTimeImmutable();

        $notifier->newConversation(new Conversation(1, 1, 2, null, $now), 'Alice', 'Alpha', new Group(2, 'Beta', null, null, 'beta@rehearsalbox.test'), $now);

        self::assertInstanceOf(NewConversationNotifierInterface::class, $notifier);
    }
}
