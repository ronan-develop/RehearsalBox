<?php

declare(strict_types=1);

namespace App\Tests\Accounting\View;

use App\Account\Entity\UserRole;
use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Entity\LedgerTotals;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Presenter\AccountingJournalView;
use App\Accounting\Presenter\MoneyFormatter;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : gabarit de la page « Comptabilité » : tout texte saisi est échappé, aucun script ni style en ligne, panneaux repliés, droits de l'interface. */
final class AccountingTemplateTest extends TestCase
{
    private const HOSTILE = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

    private function year(FiscalYearStatus $status = FiscalYearStatus::Ouvert, string $label = '2025-2026'): FiscalYear
    {
        return new FiscalYear(4, $label, new \DateTimeImmutable('2025-10-01'), new \DateTimeImmutable('2026-09-30'), $status, 10000, null, 3, null, 0);
    }

    private function entry(int $id, int $number, ?int $reverses = null, string $label = 'Cotisation'): AccountingEntry
    {
        return new AccountingEntry($id, 4, $number, new \DateTimeImmutable('2025-11-05'), $label, 2000, Direction::Recette, EntryCategory::Cotisations, PaymentMethod::Virement, self::HOSTILE, self::HOSTILE, $reverses);
    }

    /** @param list<AccountingEntry> $entries */
    private function render(array $entries = [], ?FiscalYear $year = null, string $direction = '', string $category = '', bool $noYear = false, ?array $todo = null, ?string $reminderProblem = null): string
    {
        $year ??= $this->year();
        $years = $noYear ? [] : [$year];
        $page = (new AccountingJournalView(new MoneyFormatter()))->page($years, $noYear ? null : $year, $noYear ? null : new LedgerTotals(2000, 0, ['cotisations' => 2000]), $entries, $direction, $category);

        return (new PhpTemplateRenderer(__DIR__ . '/../../../templates'))->render('admin/accounting/index', ['csrfToken' => 'token', 'page' => $page, 'todo' => $noYear ? null : ($todo ?? ['items' => [], 'hidden' => 0, 'lateCount' => 0, 'total' => 0]), 'reminderProblem' => $reminderProblem, 'currentUserRole' => UserRole::Admin]);
    }

    #[Test]
    public function testTheTodoListShowsTheStateInWordsAndOffersTheEmptyMonthOnlyWhereItApplies(): void
    {
        $html = $this->render([], null, '', '', false, ['items' => [
            ['key' => 'entries:2025-10', 'label' => '<b>Saisir</b> les mouvements', 'action' => 'Saisir', 'url' => '/admin/accounting?year=4', 'state' => 'en_retard', 'stateLabel' => 'En retard', 'late' => true, 'due' => 'avant le 05/11/2025', 'emptyMonth' => '2025-10'],
            ['key' => 'statement:2025-10', 'label' => 'Déposer le relevé', 'action' => 'Déposer', 'url' => '/admin/accounting/statements?year=4', 'state' => 'a_faire', 'stateLabel' => 'À faire', 'late' => false, 'due' => '', 'emptyMonth' => ''],
        ], 'hidden' => 2, 'lateCount' => 1, 'total' => 4]);

        self::assertStringContainsString('En retard', $html);
        self::assertStringContainsString('À faire', $html);
        self::assertStringContainsString('&lt;b&gt;Saisir', $html);
        self::assertStringNotContainsString('<b>Saisir', $html);
        self::assertSame(1, substr_count($html, 'data-action="empty-month"'));
        self::assertStringContainsString('data-month="2025-10"', $html);
        self::assertStringContainsString('Et 2 autres tâches', $html);
    }

    #[Test]
    public function testTheBannerNamesWhyNoReminderLeavesAndLinksToTheBoard(): void
    {
        $html = $this->render([], null, '', '', false, null, 'Le poste de trésorier est vide : aucun rappel ne part.');

        self::assertStringContainsString('Le poste de trésorier est vide', $html);
        self::assertStringContainsString('href="/admin/board"', $html);
        self::assertStringNotContainsString('rb-todo-banner', $this->render());
    }

    #[Test]
    public function testTheToolLinksAreAGridOfButtonsPointingToEveryAccountingPage(): void
    {
        $html = $this->render();

        self::assertStringContainsString('class="rb-ledger-links"', $html);
        foreach (['/admin/accounting/statements?year=4', '/admin/accounting/recurring', '/admin/accounting/cotisations?year=4', '/admin/accounting/years/4/report', '/admin/accounting/years/4/export.csv', '/admin/texts'] as $link) {
            self::assertStringContainsString('href="' . $link . '"', $html);
        }
    }

    #[Test]
    public function testAnEmptyTodoListSaysEverythingIsUpToDate(): void
    {
        self::assertStringContainsString('Tout est à jour', $this->render());
    }

    #[Test]
    public function testYearActionsFollowTheLifecycle(): void
    {
        $open = $this->render();
        self::assertStringContainsString('data-close-form', $open);
        self::assertStringNotContainsString('data-approve-form', $open);
        self::assertMatchesRegularExpression('/<form[^>]*data-close-form[^>]*hidden/', $open, 'confirmation repliée');

        $closed = $this->render([], $this->year(FiscalYearStatus::Cloture));
        self::assertStringNotContainsString('data-close-form', $closed);
        self::assertStringContainsString('data-approve-form', $closed);
        self::assertStringNotContainsString('data-entry-form', $closed, 'plus de saisie après la clôture');

        $approved = $this->render([], new FiscalYear(4, '2025-2026', new \DateTimeImmutable('2025-10-01'), new \DateTimeImmutable('2026-09-30'), FiscalYearStatus::Approuve, 10000, 9000, 3, new \DateTimeImmutable('2026-11-20'), 2));
        self::assertStringNotContainsString('data-approve-form', $approved);
        self::assertStringContainsString('20/11/2026', $approved);
    }

    #[Test]
    public function testEverySavedTextIsEscapedInEveryContext(): void
    {
        $html = $this->render([$this->entry(1, 1, null, self::HOSTILE)], $this->year(FiscalYearStatus::Ouvert, self::HOSTILE));

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertDoesNotMatchRegularExpression('/<[a-z]+[^>]*\sonerror=/i', $html);
    }

    #[Test]
    public function testNoInlineScriptNorInlineStyle(): void
    {
        foreach ([$this->render([$this->entry(1, 1)]), $this->render([], null, '', '', true)] as $html) {
            self::assertStringNotContainsString('style=', $html);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        }
    }

    #[Test]
    public function testTheAccountingTabIsCurrentAndTheEntryPanelStartsFolded(): void
    {
        $html = $this->render([$this->entry(1, 1)]);

        self::assertMatchesRegularExpression('#<a href="/admin/accounting" class="rb-admin-tab" aria-current="page">\s*Comptabilité\s*</a>#', $html);
        self::assertStringContainsString('data-action="new-entry"', $html);
        self::assertMatchesRegularExpression('/<form[^>]*data-entry-form[^>]*\bhidden\b/', $html);
        self::assertStringContainsString('data-year-id="4"', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="amount"[^>]*inputmode="decimal"/', $html);
    }

    #[Test]
    public function testAClosedYearOffersNoEntryPanelAndNoReversal(): void
    {
        $html = $this->render([$this->entry(1, 1)], $this->year(FiscalYearStatus::Cloture));

        self::assertStringNotContainsString('data-entry-form', $html);
        self::assertStringNotContainsString('data-action="new-entry"', $html);
        self::assertStringNotContainsString('data-action="reverse"', $html);
        self::assertStringContainsString('Clôturé', $html);
    }

    #[Test]
    public function testReversalOffersItselfOnlyOnPlainUnreversedEntries(): void
    {
        $html = $this->render([$this->entry(1, 1), $this->entry(2, 2, 1), $this->entry(3, 3)]);

        self::assertSame(1, substr_count($html, 'data-action="reverse"'), 'seule la troisième écriture est annulable');
        self::assertStringContainsString('data-entry-id="3"', $html);
        self::assertStringContainsString('Annulée', $html);
        self::assertStringContainsString('Annulation', $html);
    }

    #[Test]
    public function testTheJournalShowsSignedFrenchAmountsAndTheSummary(): void
    {
        $html = $this->render([$this->entry(1, 1)]);

        self::assertStringContainsString("+20,00\u{a0}€", $html);
        self::assertStringContainsString('Solde d\'ouverture', $html);
        self::assertStringContainsString("100,00\u{a0}€", $html);
        self::assertStringContainsString("120,00\u{a0}€", $html, 'solde actuel');
    }

    #[Test]
    public function testFiltersAreAGetFormKeepingTheYearAndTheChosenValues(): void
    {
        $html = $this->render([], null, 'depense', 'loyer');

        self::assertMatchesRegularExpression('/<form[^>]*method="get"[^>]*action="\/admin\/accounting"/', $html);
        self::assertStringContainsString('name="year" value="4"', $html);
        self::assertMatchesRegularExpression('/<option value="depense" selected>/', $html);
        self::assertMatchesRegularExpression('/<option value="loyer" selected>/', $html);
    }

    #[Test]
    public function testWithoutAnyYearTheCreationPanelIsOpenWithTheOpeningBalanceField(): void
    {
        $html = $this->render([], null, '', '', true);

        self::assertMatchesRegularExpression('/<form[^>]*data-year-form(?![^>]*\bhidden\b)[^>]*>/', $html);
        self::assertStringContainsString('name="openingBalance"', $html);
        self::assertStringNotContainsString('data-entry-form', $html);
    }

    #[Test]
    public function testTheNextYearIsOfferedOnlyOnceThePreviousOneIsClosed(): void
    {
        $open = $this->render([], $this->year(FiscalYearStatus::Ouvert));
        $closed = $this->render([], $this->year(FiscalYearStatus::Cloture));

        self::assertStringNotContainsString('data-year-form', $open);
        self::assertStringContainsString('data-year-form', $closed);
        self::assertStringContainsString('value="2026-10-01"', $closed);
        self::assertStringNotContainsString('name="openingBalance"', $closed, 'le report est calculé, pas saisi');
    }
}
