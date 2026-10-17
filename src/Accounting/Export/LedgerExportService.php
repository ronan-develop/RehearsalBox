<?php

declare(strict_types=1);

namespace App\Accounting\Export;

use App\Account\Entity\User;
use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\Direction;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Support\EuroText;
use App\Accounting\Support\MonthLabel;
use App\Accounting\Repository\EntryRepositoryInterface;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Accounting\Repository\MemberDuesRepositoryInterface;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Export complet d'un exercice (#362, art. 13 des statuts : l'expert, la banque, les liquidateurs), administrateurs uniquement. Droits et
 * existence de l'exercice sont jugés AVANT d'envoyer quoi que ce soit ; les lignes sont ensuite produites au fil de l'eau. Montants en euros
 * à la virgule (sans signe : colonnes Recette et Dépense, faciles à additionner), tout TEXTE saisi passe par CsvSafe. Le journal ne reçoit
 * que des identifiants.
 */
final class LedgerExportService
{
    private const MEMBER_LIMIT = 300;
    private const HEADER = ['Numéro', 'Date', 'Libellé', 'Catégorie', 'Sens', 'Recette (€)', 'Dépense (€)', 'Moyen de paiement', 'Pièce', 'Note', 'Adhérent', 'Mois couvert', 'Annule l\'écriture n°'];

    public function __construct(
        private readonly AdminAccess $access,
        private readonly FiscalYearRepositoryInterface $years,
        private readonly EntryRepositoryInterface $entries,
        private readonly MemberDuesRepositoryInterface $members,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return iterable<list<string>> l'en-tête puis une ligne par écriture, par numéro
     * @throws FiscalYearNotFoundException
     */
    public function rows(User $actor, int $yearId): iterable
    {
        $this->access->assertAdmin($actor);
        $this->years->find($yearId) ?? throw new FiscalYearNotFoundException();
        $this->logger->info('Comptabilité : export du livre', ['actor' => $actor->id(), 'year' => $yearId]);

        return $this->generate($yearId);
    }

    /** @return \Generator<int, list<string>> */
    private function generate(int $yearId): \Generator
    {
        $names = [];
        foreach ($this->members->listActive(self::MEMBER_LIMIT) as $member) {
            $names[$member->id] = $member->displayName;
        }
        yield self::HEADER;
        $numbers = [];
        foreach ($this->entries->eachByYear($yearId) as $entry) {
            $numbers[$entry->id] = $entry->number;
            yield $this->row($entry, $names, $numbers);
        }
    }

    /**
     * @param array<int, string> $names
     * @param array<int, int> $numbers numéros des écritures déjà lues, par identifiant
     * @return list<string>
     */
    private function row(AccountingEntry $entry, array $names, array $numbers): array
    {
        $isRecette = $entry->direction === Direction::Recette;
        $amount = EuroText::plain($entry->amountCents);

        return [
            (string) $entry->number,
            $entry->occurredOn->format('d/m/Y'),
            CsvSafe::cell($entry->label),
            $entry->category->label(),
            $entry->direction->label(),
            $isRecette ? $amount : '',
            $isRecette ? '' : $amount,
            $entry->paymentMethod->label(),
            CsvSafe::cell($entry->voucherRef ?? ''),
            CsvSafe::cell($entry->note ?? ''),
            $entry->memberUserId === null ? '' : CsvSafe::cell($names[$entry->memberUserId] ?? 'Ancien membre'),
            $entry->periodMonth === null ? '' : MonthLabel::of($entry->periodMonth),
            $entry->reversesEntryId === null ? '' : (isset($numbers[$entry->reversesEntryId]) ? (string) $numbers[$entry->reversesEntryId] : 'autre exercice'),
        ];
    }
}
