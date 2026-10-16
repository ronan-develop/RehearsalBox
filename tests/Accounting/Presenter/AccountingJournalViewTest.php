<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Presenter;

use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Entity\LedgerTotals;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Presenter\AccountingJournalView;
use App\Accounting\Presenter\MoneyFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : données de la page « Comptabilité » : texte brut (le gabarit échappe), montants à la française, filtres sur liste blanche. */
final class AccountingJournalViewTest extends TestCase
{
    private function year(int $id = 1, FiscalYearStatus $status = FiscalYearStatus::Ouvert, string $label = '2025-2026', string $start = '2025-10-01', string $end = '2026-09-30'): FiscalYear
    {
        return new FiscalYear($id, $label, new \DateTimeImmutable($start), new \DateTimeImmutable($end), $status, 10000, null, 4, null, 0);
    }

    private function entry(int $id, int $number, Direction $direction, EntryCategory $category, int $cents, ?int $reverses = null, string $label = 'Libellé'): AccountingEntry
    {
        return new AccountingEntry($id, 1, $number, new \DateTimeImmutable('2025-11-05'), $label, $cents, $direction, $category, PaymentMethod::Virement, 'F-1', null, $reverses);
    }

    private function view(): AccountingJournalView
    {
        return new AccountingJournalView(new MoneyFormatter());
    }

    /** @param list<AccountingEntry> $entries */
    private function page(array $entries, ?FiscalYear $year = null, string $direction = '', string $category = ''): array
    {
        $year ??= $this->year();
        $totals = new LedgerTotals(2000, 3000, ['cotisations' => 2000, 'loyer' => -3000]);

        return $this->view()->page([$year], $year, $totals, $entries, $direction, $category);
    }

    #[Test]
    public function testTheYearSummaryIsFormattedWithTheBalance(): void
    {
        $page = $this->page([]);

        self::assertSame("100,00\u{a0}€", $page['year']['opening']);
        self::assertSame("20,00\u{a0}€", $page['year']['recettes']);
        self::assertSame("30,00\u{a0}€", $page['year']['depenses']);
        self::assertSame("90,00\u{a0}€", $page['year']['balance'], '100 + 20 − 30');
        self::assertSame('du 01/10/2025 au 30/09/2026', $page['year']['period']);
        self::assertTrue($page['year']['isOpen']);
        self::assertSame('Ouvert', $page['year']['status']);
    }

    #[Test]
    public function testEntriesCarryASignedAmountAndHumanLabels(): void
    {
        $page = $this->page([
            $this->entry(1, 1, Direction::Recette, EntryCategory::Cotisations, 2000),
            $this->entry(2, 2, Direction::Depense, EntryCategory::Loyer, 15600),
        ]);

        [$first, $second] = $page['entries'];
        self::assertSame("+20,00\u{a0}€", $first['amount']);
        self::assertSame("−156,00\u{a0}€", $second['amount']);
        self::assertSame('Cotisations', $first['category']);
        self::assertSame('Argent reçu', $first['directionLabel']);
        self::assertSame('recette', $first['direction']);
        self::assertSame('05/11/2025', $first['date']);
        self::assertSame('Virement', $first['method']);
        self::assertSame('F-1', $first['voucher']);
    }

    #[Test]
    public function testOnlyAnOpenYearsPlainUnreversedEntriesOfferTheReversal(): void
    {
        $original = $this->entry(1, 1, Direction::Recette, EntryCategory::Cotisations, 2000);
        $reversal = $this->entry(2, 2, Direction::Depense, EntryCategory::Cotisations, 2000, 1);
        $other = $this->entry(3, 3, Direction::Recette, EntryCategory::Cotisations, 2000);

        $rows = $this->page([$original, $reversal, $other])['entries'];

        self::assertFalse($rows[0]['canReverse'], 'déjà annulée');
        self::assertTrue($rows[0]['isReversed']);
        self::assertTrue($rows[1]['isReversal']);
        self::assertFalse($rows[1]['canReverse'], 'une annulation ne s\'annule pas');
        self::assertTrue($rows[2]['canReverse']);
        $closed = $this->page([$other], $this->year(1, FiscalYearStatus::Cloture))['entries'];
        self::assertFalse($closed[0]['canReverse'], 'exercice clos');
    }

    #[Test]
    public function testFiltersAreAWhitelistAndAnythingElseIsIgnored(): void
    {
        $entries = [
            $this->entry(1, 1, Direction::Recette, EntryCategory::Cotisations, 2000),
            $this->entry(2, 2, Direction::Depense, EntryCategory::Loyer, 15600),
            $this->entry(3, 3, Direction::Depense, EntryCategory::Electricite, 4000),
        ];

        self::assertCount(2, $this->page($entries, null, 'depense')['entries']);
        self::assertCount(1, $this->page($entries, null, '', 'electricite')['entries']);
        self::assertCount(0, $this->page($entries, null, 'recette', 'loyer')['entries']);
        foreach (['', "' OR 1=1 --", '<script>', 'DEPENSE', 'loyer%', '0'] as $hostile) {
            self::assertCount(3, $this->page($entries, null, $hostile, $hostile)['entries'], "filtre « {$hostile} » ignoré");
        }
        $page = $this->page($entries, null, 'depense', 'loyer');
        self::assertSame('depense', $page['filters']['direction']);
        self::assertSame('loyer', $page['filters']['category']);
        self::assertTrue($page['filters']['active']);
    }

    #[Test]
    public function testTextsStayRawSoTheTemplateAloneEscapes(): void
    {
        $page = $this->page([$this->entry(1, 1, Direction::Recette, EntryCategory::Cotisations, 2000, null, '<script>alert(1)</script>')]);

        self::assertSame('<script>alert(1)</script>', $page['entries'][0]['label']);
    }

    #[Test]
    public function testWithoutAnyYearThePageOffersTheCreationOnly(): void
    {
        $page = $this->view()->page([], null, null, [], '', '');

        self::assertNull($page['year']);
        self::assertSame([], $page['entries']);
        self::assertSame([], $page['years']);
        self::assertSame('', $page['next']['startsOn']);
    }

    #[Test]
    public function testTheNextYearStartsTheDayAfterTheLatestOne(): void
    {
        $latest = $this->year(1, FiscalYearStatus::Cloture);
        $page = $this->view()->page([$latest], $latest, new LedgerTotals(0, 0, []), [], '', '');

        self::assertSame('2026-10-01', $page['next']['startsOn']);
        self::assertSame('2027-09-30', $page['next']['endsOn']);
        self::assertSame('2026-2027', $page['next']['label']);
        self::assertTrue($page['next']['canCreate'], 'le précédent est clôturé');
        $open = $this->view()->page([$this->year()], $this->year(), new LedgerTotals(0, 0, []), [], '', '');
        self::assertFalse($open['next']['canCreate'], 'l\'exercice précédent doit d\'abord être clôturé');
    }

    #[Test]
    public function testFormChoicesListEveryCategoryWithItsDirection(): void
    {
        $form = $this->page([])['form'];

        $loyer = array_values(array_filter($form['categories'], static fn (array $c): bool => $c['value'] === 'loyer'))[0];
        self::assertSame('depense', $loyer['direction']);
        self::assertSame('Loyer', $loyer['label']);
        self::assertSame(['recette', 'depense'], array_column($form['directions'], 'value'));
        self::assertSame(['especes', 'virement', 'cheque', 'carte'], array_column($form['methods'], 'value'));
    }
}
