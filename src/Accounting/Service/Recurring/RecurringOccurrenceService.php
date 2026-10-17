<?php

declare(strict_types=1);

namespace App\Accounting\Service\Recurring;

use App\Account\Entity\User;
use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\RecurringTemplate;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\RecurringTemplateNotFoundException;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Accounting\Repository\RecurringOccurrenceRepositoryInterface;
use App\Accounting\Repository\RecurringTemplateRepositoryInterface;
use App\Accounting\Service\EntryInputPolicy;
use App\Accounting\Service\EntryService;
use App\Accounting\Support\EuroText;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Échéances des modèles récurrents (#362) : liste « À confirmer » (échéances atteintes, pas encore décidées) puis, par le trésorier,
 * CONFIRMATION (l'écriture est créée par EntryService : mêmes règles, même numérotation, même verrou d'exercice) ou ABANDON. Chaque
 * échéance n'est décidée qu'UNE fois : la décision est écrite dans la MÊME transaction que l'écriture, sous une clé unique, donc un double
 * clic ou deux onglets ne créent jamais deux écritures. Une échéance future ne se confirme pas ; un montant variable se saisit à chaque fois.
 */
final class RecurringOccurrenceService
{
    private const TEMPLATE_LIMIT = 200;

    public function __construct(
        private readonly AdminAccess $access,
        private readonly RecurringTemplateRepositoryInterface $templates,
        private readonly RecurringOccurrenceRepositoryInterface $occurrences,
        private readonly FiscalYearRepositoryInterface $years,
        private readonly EntryService $entries,
        private readonly RecurrenceSchedule $schedule,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<PendingOccurrence> échéances atteintes de l'exercice ouvert, de la plus ancienne à la plus récente (rien pour un exercice clos) */
    public function pending(User $actor, FiscalYear $year, \DateTimeImmutable $today): array
    {
        $this->access->assertAdmin($actor);
        if (!$year->isOpen()) {
            return [];
        }
        $active = array_values(array_filter($this->templates->listAll(self::TEMPLATE_LIMIT), static fn (RecurringTemplate $t): bool => $t->isActive));
        $decided = $this->occurrences->decidedFor(array_map(static fn (RecurringTemplate $t): int => $t->id, $active));
        $to = min($today, $year->endsOn);
        $pending = [];
        foreach ($active as $template) {
            foreach ($this->schedule->periodsBetween($template, $year->startsOn, $to) as $period) {
                if (!isset($decided[$template->id . '|' . $period->format('Y-m-d')])) {
                    $pending[] = new PendingOccurrence($template, $period, $this->schedule->dueOn($template, $period));
                }
            }
        }
        usort($pending, static fn (PendingOccurrence $a, PendingOccurrence $b): int => [$a->dueOn, $a->template->id] <=> [$b->dueOn, $b->template->id]);

        return $pending;
    }

    /**
     * @param array<string, mixed> $input `period` (AAAA-MM-01), `amount` (obligatoire si le montant est variable), `occurredOn` (défaut : la date
     *                                    d'échéance), `voucherRef` (défaut : celle du modèle)
     * @throws RecurringTemplateNotFoundException
     * @throws AccountingValidationException échéance inconnue, future ou déjà décidée ; saisie refusée ; aucun exercice ouvert à cette date
     */
    public function confirm(User $actor, int $templateId, array $input, \DateTimeImmutable $today, ?\DateTimeImmutable $now = null): AccountingEntry
    {
        $this->access->assertAdmin($actor);
        $now ??= new \DateTimeImmutable();
        [$template, $period] = $this->dueOccurrence($templateId, $input, $today);
        $errors = [];
        $dateText = self::text($input['occurredOn'] ?? null) !== '' ? self::text($input['occurredOn']) : $this->schedule->dueOn($template, $period)->format('Y-m-d');
        $date = EntryInputPolicy::date($dateText, 'occurredOn', $errors);
        $year = $date === null ? null : $this->years->findOpenContaining($date);
        if ($date === null || $year === null) {
            throw new AccountingValidationException($errors + ['occurredOn' => 'Aucun exercice ouvert à cette date.']);
        }
        $amount = self::text($input['amount'] ?? null) !== '' ? $input['amount'] : ($template->amountCents === null ? null : EuroText::plain($template->amountCents));
        $voucher = self::text($input['voucherRef'] ?? null) !== '' ? $input['voucherRef'] : $template->voucherRef;

        $entry = $this->entries->record($actor, $year->id, [
            'occurredOn' => $dateText,
            'label' => $template->label,
            'amount' => $amount,
            'direction' => $template->direction->value,
            'category' => $template->category->value,
            'paymentMethod' => $template->paymentMethod->value,
            'voucherRef' => $voucher,
        ], $now, function (AccountingEntry $written) use ($template, $period, $actor, $now): void {
            if (!$this->occurrences->decide($template->id, $period, 'confirmee', $written->id, $actor->id(), $now)) {
                throw new AccountingValidationException(['period' => 'Cette échéance est déjà traitée.']);
            }
        });
        $this->logger->info('Comptabilité : échéance confirmée', ['actor' => $actor->id(), 'template' => $templateId, 'entry' => $entry->id]);

        return $entry;
    }

    /**
     * @param array<string, mixed> $input `period` (AAAA-MM-01)
     * @throws RecurringTemplateNotFoundException
     * @throws AccountingValidationException échéance inconnue, future ou déjà décidée
     */
    public function ignore(User $actor, int $templateId, array $input, \DateTimeImmutable $today, ?\DateTimeImmutable $now = null): void
    {
        $this->access->assertAdmin($actor);
        [$template, $period] = $this->dueOccurrence($templateId, $input, $today);
        if (!$this->occurrences->decide($template->id, $period, 'ignoree', null, $actor->id(), $now ?? new \DateTimeImmutable())) {
            throw new AccountingValidationException(['period' => 'Cette échéance est déjà traitée.']);
        }
        $this->logger->info('Comptabilité : échéance abandonnée', ['actor' => $actor->id(), 'template' => $templateId]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: RecurringTemplate, 1: \DateTimeImmutable} le modèle actif et le mois de son échéance atteinte
     */
    private function dueOccurrence(int $templateId, array $input, \DateTimeImmutable $today): array
    {
        $template = $this->templates->find($templateId) ?? throw new RecurringTemplateNotFoundException();
        $errors = [];
        $period = EntryInputPolicy::date($input['period'] ?? null, 'period', $errors);
        if ($period === null || !$template->isActive || !$this->schedule->isScheduled($template, $period) || $this->schedule->dueOn($template, $period) > $today) {
            throw new AccountingValidationException(['period' => 'Cette échéance n\'existe pas ou n\'est pas encore atteinte.']);
        }

        return [$template, $period];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
