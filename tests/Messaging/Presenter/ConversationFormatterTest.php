<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Presenter;

use App\Messaging\Entity\ConversationMessage;
use App\Messaging\Entity\SeenReceipt;
use App\Messaging\Presenter\ConversationFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationFormatterTest extends TestCase
{
    private ConversationFormatter $formatter;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        // Les dates sont stockées en UTC ; l'affichage est en heure de Paris (CEST = UTC+2 début octobre).
        $this->formatter = new ConversationFormatter(new \DateTimeZone('Europe/Paris'));
        $this->now = new \DateTimeImmutable('2026-10-04 16:00:00', new \DateTimeZone('UTC'));
    }

    private function utc(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private function message(string $body, bool $mine = false, string $author = 'Alice', bool $system = false): array
    {
        return [new ConversationMessage(1, 7, $mine ? 10 : 11, $author, $body, $this->utc('2026-10-04 10:00:00'), $system), $mine ? 10 : 99];
    }

    #[Test]
    public function testTimeIsShownInTheDisplayTimezone(): void
    {
        self::assertSame('09:05', $this->formatter->time($this->utc('2026-10-04 07:05:00')));
        self::assertSame('01:30', $this->formatter->time($this->utc('2026-10-03 23:30:00')), 'minuit passé à Paris');
    }

    #[Test]
    public function testListDateShowsTheTimeTodayAndHierOnlyWithinTwentyFourHours(): void
    {
        self::assertSame('09:05', $this->formatter->listDate($this->utc('2026-10-04 07:05:00'), $this->now));
        self::assertSame('Hier', $this->formatter->listDate($this->utc('2026-10-03 17:00:01'), $this->now), 'hier, 22 h 59 plus tôt');
        self::assertSame('Hier', $this->formatter->listDate($this->utc('2026-10-03 16:00:01'), $this->now), '23 h 59 min 59 s : encore moins de 24 h');
    }

    #[Test]
    public function testListDateShowsTheDateFromTwentyFourHoursOn(): void
    {
        self::assertSame('03/10', $this->formatter->listDate($this->utc('2026-10-03 16:00:00'), $this->now), '24 h pile : la date');
        self::assertSame('03/10', $this->formatter->listDate($this->utc('2026-10-03 07:05:00'), $this->now), 'hier mais il y a plus de 24 h');
        self::assertSame('30/09', $this->formatter->listDate($this->utc('2026-09-30 07:05:00'), $this->now));
    }

    #[Test]
    public function testListDateAddsTheYearWhenItIsNotTheCurrentOne(): void
    {
        self::assertSame('31/12/2025', $this->formatter->listDate($this->utc('2025-12-31 12:00:00'), $this->now));
        $newYear = $this->utc('2027-01-01 10:00:00');
        self::assertSame('31/12/2026', $this->formatter->listDate($this->utc('2026-12-31 08:00:00'), $newYear), 'l\'année change au 1er janvier local : l\'année est affichée');
        self::assertSame('29/12', $this->formatter->listDate($this->utc('2026-12-29 10:00:00'), $this->utc('2026-12-31 12:00:00')), 'même année : pas d\'année');
    }

    #[Test]
    public function testListDateOfAFutureMessageFromClockSkewShowsTheTime(): void
    {
        self::assertSame('18:05', $this->formatter->listDate($this->utc('2026-10-04 16:05:00'), $this->now));
    }

    #[Test]
    public function testDayLabelUsesTheLocalCalendarDay(): void
    {
        self::assertSame("Aujourd'hui", $this->formatter->dayLabel($this->utc('2026-10-04 07:05:00'), $this->now));
        self::assertSame('Hier', $this->formatter->dayLabel($this->utc('2026-10-03 07:05:00'), $this->now));
        self::assertSame('30/09/2026', $this->formatter->dayLabel($this->utc('2026-09-30 07:05:00'), $this->now));
        self::assertSame("Aujourd'hui", $this->formatter->dayLabel($this->utc('2026-10-03 22:30:00'), $this->now), '22 h 30 UTC = 00 h 30 à Paris : déjà le 4');
    }

    #[Test]
    public function testTypingTextNamesWhoIsWriting(): void
    {
        self::assertSame('', $this->formatter->typingText([]));
        self::assertSame('Bob écrit…', $this->formatter->typingText(['Bob']));
        self::assertSame('Bob et Zoé écrivent…', $this->formatter->typingText(['Bob', 'Zoé']));
        self::assertSame('Bob, Zoé et 1 autre écrivent…', $this->formatter->typingText(['Bob', 'Zoé', 'Léa']));
        self::assertSame('A, B et 2 autres écrivent…', $this->formatter->typingText(['A', 'B', 'C', 'D']));
    }

    #[Test]
    public function testSeenTextIsEnvoyeNamesOrACount(): void
    {
        self::assertSame('', $this->formatter->seenText(null));
        self::assertSame('', $this->formatter->seenText(new SeenReceipt(1, [], 0)));
        self::assertSame('Envoyé', $this->formatter->seenText(new SeenReceipt(1, [], 3)));
        self::assertSame('Vu par Bob', $this->formatter->seenText(new SeenReceipt(1, ['Bob'], 3)));
        self::assertSame('Vu par Bob et Zoé', $this->formatter->seenText(new SeenReceipt(1, ['Bob', 'Zoé'], 3)));
        self::assertSame('Vu par 3 sur 8', $this->formatter->seenText(new SeenReceipt(1, ['Bob', 'Zoé', 'Léa'], 8)));
    }

    #[Test]
    public function testSystemLineConjugatesForMeAndNamesTheAuthorOtherwise(): void
    {
        [$mine] = $this->message('a renommé la conversation « X »', true, 'Alice', true);
        [$theirs] = $this->message('a retiré le titre de la conversation', false, 'Bob', true);

        self::assertSame('Vous avez renommé la conversation « X »', $this->formatter->systemLine($mine, true));
        self::assertSame('Bob a retiré le titre de la conversation', $this->formatter->systemLine($theirs, false));
    }

    #[Test]
    public function testPreviewIsAuthorColonBodyOrTheSystemLine(): void
    {
        [$mine] = $this->message('Salut', true);
        [$theirs] = $this->message('Hello', false, 'Bob');
        [$system] = $this->message('a renommé la conversation « X »', false, 'Bob', true);

        self::assertSame('Vous : Salut', $this->formatter->preview($mine, true));
        self::assertSame('Bob : Hello', $this->formatter->preview($theirs, false));
        self::assertSame('Bob a renommé la conversation « X »', $this->formatter->preview($system, false));
    }
}
