<?php

declare(strict_types=1);

namespace App\Tests\Presenter;

use App\Entity\ConversationMessage;
use App\Entity\Group;
use App\Presenter\ConversationFormatter;
use App\Presenter\ConversationTimeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationTimelineTest extends TestCase
{
    private ConversationTimeline $timeline;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->timeline = new ConversationTimeline(new ConversationFormatter(new \DateTimeZone('Europe/Paris')));
        $this->now = new \DateTimeImmutable('2026-10-04 16:00:00', new \DateTimeZone('UTC'));
    }

    private function msg(int $id, int $authorId, string $author, string $at, string $body = 'texte', bool $system = false): ConversationMessage
    {
        return new ConversationMessage($id, 7, $authorId, $author, $body, new \DateTimeImmutable($at, new \DateTimeZone('UTC')), $system);
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private function kinds(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['type'] . ($row['type'] === 'message' ? ':' . $row['id'] : ''), $rows);
    }

    #[Test]
    public function testMentionsAreSplitOutOfTheTextAndFlaggedWhenTheViewerIsTagged(): void
    {
        $rows = $this->timeline->rows(
            [$this->msg(1, 11, 'Bob', '2026-10-04 08:00:00', 'Salut @Alice et @Denis')],
            10,
            $this->now,
            mentions: [1 => [10 => '@Alice', 12 => '@Denis']],
        );
        $message = $rows[1];

        self::assertSame(['Salut ', '@Alice', ' et ', '@Denis'], array_column($message['segments'], 'text'));
        self::assertTrue($message['mentionsMe'], 'Alice (10) est taguée');
        self::assertSame('Salut @Alice et @Denis', $message['body'], 'le texte brut reste disponible');
    }

    #[Test]
    public function testAMessageWithoutMentionHasOneSegmentAndNoHighlight(): void
    {
        $rows = $this->timeline->rows([$this->msg(1, 11, 'Bob', '2026-10-04 08:00:00', 'Salut')], 10, $this->now);

        self::assertSame([['text' => 'Salut', 'mention' => false, 'me' => false]], $rows[1]['segments']);
        self::assertFalse($rows[1]['mentionsMe']);
    }

    #[Test]
    public function testAnEditedMessageShowsItsEditTimeAndOnlyMyRecentMessagesAreEditable(): void
    {
        $old = new ConversationMessage(1, 7, 10, 'Alice', 'Ancien', new \DateTimeImmutable('2026-10-04 08:00:00', new \DateTimeZone('UTC')));
        $recent = new ConversationMessage(2, 7, 10, 'Alice', 'Récent', new \DateTimeImmutable('2026-10-04 15:50:00', new \DateTimeZone('UTC')), false, new \DateTimeImmutable('2026-10-04 15:55:00', new \DateTimeZone('UTC')));
        $theirs = new ConversationMessage(3, 7, 11, 'Bob', 'De Bob', new \DateTimeImmutable('2026-10-04 15:55:00', new \DateTimeZone('UTC')));
        $system = new ConversationMessage(4, 7, 10, 'Alice', 'a renommé', new \DateTimeImmutable('2026-10-04 15:56:00', new \DateTimeZone('UTC')), true);

        $rows = array_values(array_filter(
            $this->timeline->rows([$old, $recent, $theirs, $system], 10, $this->now, editableSince: $this->now->modify('-15 minutes')),
            static fn (array $r): bool => $r['type'] === 'message',
        ));

        self::assertSame([null, '17:55', null], array_column($rows, 'edited'), 'heure locale de la modification');
        self::assertSame([false, true, false], array_column($rows, 'editable'), 'le mien et récent seulement (jamais un message des autres)');
    }

    #[Test]
    public function testWithoutAnEditWindowNothingIsEditable(): void
    {
        $rows = $this->timeline->rows([$this->msg(1, 10, 'Alice', '2026-10-04 15:59:00')], 10, $this->now);

        self::assertFalse($rows[1]['editable']);
    }

    #[Test]
    public function testNothingToShowGivesNoRows(): void
    {
        self::assertSame([], $this->timeline->rows([], 10, $this->now));
    }

    #[Test]
    public function testDaySeparatorsWithLabelsComeBeforeEachDay(): void
    {
        $rows = $this->timeline->rows([
            $this->msg(1, 11, 'Bob', '2026-09-30 08:00:00'),
            $this->msg(2, 11, 'Bob', '2026-10-03 08:00:00'),
            $this->msg(3, 10, 'Alice', '2026-10-03 18:00:00'),
            $this->msg(4, 11, 'Bob', '2026-10-04 08:00:00'),
        ], 10, $this->now);

        self::assertSame(['day', 'message:1', 'day', 'message:2', 'message:3', 'day', 'message:4'], $this->kinds($rows));
        self::assertSame(['30/09/2026', 'Hier', "Aujourd'hui"], array_values(array_map(static fn ($r) => $r['label'], array_filter($rows, static fn ($r) => $r['type'] === 'day'))));
    }

    #[Test]
    public function testOnlyTheFirstMessageOfARunByTheSameAuthorStartsARun(): void
    {
        $rows = $this->timeline->rows([
            $this->msg(1, 11, 'Bob', '2026-10-04 08:00:00'),
            $this->msg(2, 11, 'Bob', '2026-10-04 08:01:00'),
            $this->msg(3, 12, 'Zoé', '2026-10-04 08:02:00'),
            $this->msg(4, 11, 'Bob', '2026-10-04 08:03:00'),
        ], 10, $this->now);
        $messages = array_values(array_filter($rows, static fn ($r) => $r['type'] === 'message'));

        self::assertSame([true, false, true, true], array_column($messages, 'startsRun'));
    }

    #[Test]
    public function testAMessageCarriesAuthorInitialsTimeBodyAndWhetherItIsMine(): void
    {
        $rows = $this->timeline->rows([$this->msg(1, 10, 'Alice Martin', '2026-10-04 07:05:00', "Salut\nÇa va ?")], 10, $this->now);
        $message = $rows[1];

        self::assertSame('message', $message['type']);
        self::assertTrue($message['mine']);
        self::assertSame('Alice Martin', $message['author']);
        self::assertSame('AM', $message['initials']);
        self::assertSame("Salut\nÇa va ?", $message['body']);
        self::assertSame('09:05', $message['time']);
    }

    #[Test]
    public function testTheAuthorGroupGivesTheBadgeColourOnlyWhenItIsAValidHexColour(): void
    {
        $good = new Group(3, 'Alpha', null, '#aa0000', 'a@example.test');
        $bad = new Group(4, 'Beta', null, 'red; background:url(x)', 'b@example.test');
        $rows = $this->timeline->rows([
            $this->msg(1, 11, 'Bob', '2026-10-04 08:00:00'),
            $this->msg(2, 12, 'Zoé', '2026-10-04 08:01:00'),
            $this->msg(3, 13, 'Léa', '2026-10-04 08:02:00'),
        ], 10, $this->now, [11 => $good, 12 => $bad]);
        $messages = array_values(array_filter($rows, static fn ($r) => $r['type'] === 'message'));

        self::assertSame(['#aa0000', null, null], array_column($messages, 'color'));
        self::assertSame(['Alpha', 'Beta', null], array_column($messages, 'groupName'));
    }

    #[Test]
    public function testTheUnreadMarkerComesBeforeTheFirstUnreadMessageAndRestartsTheRun(): void
    {
        $rows = $this->timeline->rows([
            $this->msg(1, 11, 'Bob', '2026-10-04 08:00:00'),
            $this->msg(2, 11, 'Bob', '2026-10-04 08:01:00'),
        ], 10, $this->now, [], 2);

        self::assertSame(['day', 'message:1', 'unread', 'message:2'], $this->kinds($rows));
        self::assertTrue($rows[3]['startsRun'], 'après le séparateur, l\'auteur est de nouveau affiché');
    }

    #[Test]
    public function testSystemLinesAreCentredTextAndBreakTheRun(): void
    {
        $rows = $this->timeline->rows([
            $this->msg(1, 11, 'Bob', '2026-10-04 08:00:00'),
            $this->msg(2, 10, 'Alice', '2026-10-04 08:01:00', 'a renommé la conversation « X »', true),
            $this->msg(3, 11, 'Bob', '2026-10-04 08:02:00'),
        ], 10, $this->now);

        self::assertSame(['day', 'message:1', 'system', 'message:3'], $this->kinds($rows));
        self::assertSame('Vous avez renommé la conversation « X »', $rows[2]['text']);
        self::assertTrue($rows[3]['startsRun']);
    }

    #[Test]
    public function testIncrementalRowsContinueTheDayAndTheRunOfThePreviousMessage(): void
    {
        $previous = $this->msg(1, 11, 'Bob', '2026-10-04 08:00:00');
        $new = [$this->msg(2, 11, 'Bob', '2026-10-04 08:05:00'), $this->msg(3, 10, 'Alice', '2026-10-04 08:06:00')];

        $rows = $this->timeline->rows($new, 10, $this->now, [], null, $previous);

        self::assertSame(['message:2', 'message:3'], $this->kinds($rows), 'même jour : pas de nouveau séparateur');
        self::assertFalse($rows[0]['startsRun'], 'même auteur que le message précédent');
    }

    #[Test]
    public function testIncrementalRowsStartANewDayWhenThePreviousMessageIsFromAnotherDay(): void
    {
        $previous = $this->msg(1, 11, 'Bob', '2026-10-03 08:00:00');

        $rows = $this->timeline->rows([$this->msg(2, 11, 'Bob', '2026-10-04 08:05:00')], 10, $this->now, [], null, $previous);

        self::assertSame(['day', 'message:2'], $this->kinds($rows));
        self::assertTrue($rows[1]['startsRun']);
    }

    #[Test]
    public function testIncrementalRowsAfterASystemLineStartANewRun(): void
    {
        $previous = $this->msg(1, 10, 'Alice', '2026-10-04 08:00:00', 'a renommé la conversation « X »', true);

        $rows = $this->timeline->rows([$this->msg(2, 11, 'Bob', '2026-10-04 08:05:00')], 10, $this->now, [], null, $previous);

        self::assertTrue($rows[0]['startsRun']);
    }
}
