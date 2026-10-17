<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Import\Suggest;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\Member;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Entity\Periodicity;
use App\Accounting\Entity\RecurringTemplate;
use App\Accounting\Import\ImportLine;
use App\Accounting\Import\Rules\LabelRule;
use App\Accounting\Import\Suggest\LineSuggester;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : propositions pour les lignes en attente : échéance récurrente, cotisation d'un adhérent, règle ; le doute ne propose rien. */
final class LineSuggesterTest extends TestCase
{
    private function line(int $id, string $date, string $label, int $cents, Direction $direction): ImportLine
    {
        return new ImportLine($id, 1, $id, new \DateTimeImmutable($date), $label, $cents, $direction, ImportLine::PENDING, null, new \DateTimeImmutable('2025-11-10'));
    }

    private function rent(): RecurringTemplate
    {
        return new RecurringTemplate(1, 'Loyer du local', Direction::Depense, EntryCategory::Loyer, PaymentMethod::Virement, 49800, 'Virement loyer', Periodicity::Trimestrielle, 5, new \DateTimeImmutable('2025-10-01'), true);
    }

    private function electricity(): RecurringTemplate
    {
        return new RecurringTemplate(2, 'EDF', Direction::Depense, EntryCategory::Electricite, PaymentMethod::Virement, null, null, Periodicity::Mensuelle, 10, new \DateTimeImmutable('2025-10-01'), true);
    }

    /** @return list<Member> */
    private function members(): array
    {
        return [new Member(7, 'Jean Dupont'), new Member(8, 'Alice Martin'), new Member(9, 'Jean Durand')];
    }

    #[Test]
    public function testAFixedAmountNearTheDueDateIsMatchedToTheRecurringTemplate(): void
    {
        $out = (new LineSuggester())->suggest([$this->line(1, '2025-10-06', 'PRLV LOYER OCT', 49800, Direction::Depense)], [$this->rent()], [], [], [], []);

        self::assertSame('1|2025-10-01', $out[1]->occurrence);
        self::assertSame(EntryCategory::Loyer, $out[1]->category);
        self::assertSame('Virement loyer', $out[1]->voucherRef);
        self::assertStringContainsString('Loyer du local', $out[1]->source);
    }

    #[Test]
    public function testAnAmountOrADateOutOfReachOrADecidedDueIsNotMatched(): void
    {
        $suggester = new LineSuggester();

        self::assertSame([], $suggester->suggest([$this->line(1, '2025-10-06', 'X', 49700, Direction::Depense)], [$this->rent()], [], [], [], []), 'autre montant');
        self::assertSame([], $suggester->suggest([$this->line(1, '2025-10-25', 'X', 49800, Direction::Depense)], [$this->rent()], [], [], [], []), 'trop loin de l\'échéance');
        self::assertSame([], $suggester->suggest([$this->line(1, '2025-10-06', 'X', 49800, Direction::Recette)], [$this->rent()], [], [], [], []), 'autre sens');
        self::assertSame([], $suggester->suggest([$this->line(1, '2025-10-06', 'X', 49800, Direction::Depense)], [$this->rent()], ['1|2025-10-01' => true], [], [], []), 'échéance déjà décidée');
    }

    #[Test]
    public function testAVariableAmountTemplateIsMatchedByItsLabelAndEachDueIsUsedOnce(): void
    {
        $lines = [$this->line(1, '2025-10-11', 'PRLV EDF 123', 6340, Direction::Depense), $this->line(2, '2025-10-12', 'PRLV EDF 124', 6400, Direction::Depense)];

        $out = (new LineSuggester())->suggest($lines, [$this->electricity()], [], [], [], []);

        self::assertSame('2|2025-10-01', $out[1]->occurrence);
        self::assertArrayNotHasKey(2, $out, 'la même échéance ne sert pas deux lignes');
    }

    #[Test]
    public function testATwentyEuroReceiptNamingExactlyOneMemberIsProposedAsHisDuesForTheMonth(): void
    {
        $out = (new LineSuggester())->suggest([$this->line(1, '2025-11-14', 'VIR M ALICE MARTIN COTIS', 2000, Direction::Recette)], [], [], $this->members(), [], []);

        self::assertSame([8, '2025-11', EntryCategory::Cotisations], [$out[1]->memberUserId, $out[1]->periodMonth, $out[1]->category]);
        self::assertStringContainsString('Alice Martin', $out[1]->source);
    }

    #[Test]
    public function testDoubtMeansNoDuesProposal(): void
    {
        $suggester = new LineSuggester();

        self::assertSame([], $suggester->suggest([$this->line(1, '2025-11-14', 'VIR JEAN', 2000, Direction::Recette)], [], [], $this->members(), [], []), 'prénom seul : deux Jean');
        self::assertSame([], $suggester->suggest([$this->line(1, '2025-11-14', 'VIR ALICE MARTIN', 2500, Direction::Recette)], [], [], $this->members(), [], []), 'autre montant');
        self::assertSame([], $suggester->suggest([$this->line(1, '2025-11-14', 'VIR ALICE MARTIN', 2000, Direction::Depense)], [], [], $this->members(), [], []), 'argent dépensé');
        self::assertSame([], $suggester->suggest([$this->line(1, '2025-11-14', 'VIR ALICE MARTIN', 2000, Direction::Recette)], [], [], $this->members(), ['8|2025-11-01' => true], []), 'mois déjà payé');
        self::assertSame([], $suggester->suggest([$this->line(1, '2025-11-14', 'VIR ALICEMARTIN', 2000, Direction::Recette)], [], [], $this->members(), [], []), 'nom collé à autre chose : pas un mot entier');
    }

    #[Test]
    public function testTheSameMemberAndMonthIsProposedOnlyOnceInAFile(): void
    {
        $lines = [$this->line(1, '2025-11-14', 'VIR ALICE MARTIN', 2000, Direction::Recette), $this->line(2, '2025-11-20', 'VIR ALICE MARTIN', 2000, Direction::Recette)];

        $out = (new LineSuggester())->suggest($lines, [], [], $this->members(), [], []);

        self::assertArrayHasKey(1, $out);
        self::assertArrayNotHasKey(2, $out);
    }

    #[Test]
    public function testARuleProposesItsTargetWhenNothingMoreSpecificApplies(): void
    {
        $rules = [new LabelRule(1, 'frais bancaires', Direction::Depense, EntryCategory::FraisBancaires, PaymentMethod::Carte)];

        $out = (new LineSuggester())->suggest([$this->line(1, '2025-11-30', 'FRAIS BANCAIRES TENUE DE COMPTE', 350, Direction::Depense)], [], [], [], [], $rules);

        self::assertSame([EntryCategory::FraisBancaires, PaymentMethod::Carte], [$out[1]->category, $out[1]->paymentMethod]);
        self::assertStringContainsString('Règle', $out[1]->source);
    }

    #[Test]
    public function testTheRecurringMatchComesBeforeTheRule(): void
    {
        $rules = [new LabelRule(1, 'loyer', Direction::Depense, EntryCategory::AutresDepenses, PaymentMethod::Virement)];

        $out = (new LineSuggester())->suggest([$this->line(1, '2025-10-06', 'PRLV LOYER', 49800, Direction::Depense)], [$this->rent()], [], [], [], $rules);

        self::assertSame(EntryCategory::Loyer, $out[1]->category);
    }
}
