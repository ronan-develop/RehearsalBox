<?php

declare(strict_types=1);

namespace App\Accounting\Import\Validate;

use App\Account\Entity\User;
use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Import\ImportLine;
use App\Accounting\Import\Rules\LabelNormalizer;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Accounting\Repository\Import\ImportRepositoryInterface;
use App\Accounting\Repository\Import\LabelRuleRepositoryInterface;
use App\Accounting\Repository\RecurringOccurrenceRepositoryInterface;
use App\Accounting\Repository\RecurringTemplateRepositoryInterface;
use App\Accounting\Service\EntryInputPolicy;
use App\Accounting\Service\EntryService;
use App\Accounting\Service\Recurring\RecurrenceSchedule;
use App\Accounting\Support\EuroText;
use App\Security\AdminAccess;
use App\Support\StrictId;
use Psr\Log\LoggerInterface;

/**
 * « Valider les écritures » (#362) : les lignes cochées par le trésorier deviennent des écritures en UNE transaction, tout ou rien, par le
 * service d'écriture normal (numérotation sous verrou, exercice ouvert, date dans l'exercice, pièce obligatoire pour une dépense, un seul
 * paiement par adhérent et par mois). La date, le montant et le sens viennent de la LIGNE mise en attente, jamais du client ; le libellé, la
 * catégorie, le moyen de paiement, la pièce et la note sont ceux que la personne a relus. Dans la même transaction : la ligne passe à
 * « validée », l'échéance d'un modèle récurrent est confirmée, la règle « Retenir pour la prochaine fois » est créée. Une ligne déjà traitée
 * (double clic, autre onglet) fait tout refuser. Toutes les erreurs sont rapportées ensemble, clés `<ligne>.<champ>`.
 */
final class BatchValidationService
{
    public const MAX_ROWS = 500;

    public function __construct(
        private readonly AdminAccess $access,
        private readonly ImportRepositoryInterface $imports,
        private readonly FiscalYearRepositoryInterface $years,
        private readonly EntryService $entries,
        private readonly LabelRuleRepositoryInterface $rules,
        private readonly RecurringTemplateRepositoryInterface $templates,
        private readonly RecurringOccurrenceRepositoryInterface $occurrences,
        private readonly RecurrenceSchedule $schedule,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<mixed> $rows `line`, `label`, `category`, `paymentMethod`, `voucherRef`, `note`, `memberUserId`, `periodMonth`, `occurrence`
     *                          (`idModèle|AAAA-MM-01`), `remember` (booléen), `rulePattern`
     * @return array{entries: int, rules: int}
     * @throws AccountingValidationException
     */
    public function validate(User $actor, array $rows, ?\DateTimeImmutable $now = null): array
    {
        $this->access->assertAdmin($actor);
        $now ??= new \DateTimeImmutable();
        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            throw new AccountingValidationException(['rows' => 'Cochez entre 1 et ' . self::MAX_ROWS . ' lignes.']);
        }
        $pending = [];
        foreach ($this->imports->pending(self::MAX_ROWS) as $line) {
            $pending[$line->id] = $line;
        }

        $items = [];
        $errors = [];
        $rulesCreated = 0;
        foreach ($rows as $index => $row) {
            $id = is_array($row) ? StrictId::from($row['line'] ?? null) : null;
            if ($id === null || isset($items[$id])) {
                $errors["rows.{$index}"] = 'Ligne invalide ou en double.';
                continue;
            }
            $line = $pending[$id] ?? null;
            $year = $line === null ? null : $this->years->findOpenContaining($line->occurredOn);
            if ($line === null || $year === null) {
                $errors["{$id}.line"] = $line === null ? 'Cette ligne a déjà été traitée.' : 'Aucun exercice ouvert à la date de cette ligne.';
                continue;
            }
            $problem = [];
            $item = $this->item($actor, $row, $line, $year->id, $now, $problem);
            if ($problem !== []) {
                foreach ($problem as $field => $message) {
                    $errors["{$id}.{$field}"] = $message;
                }
                continue;
            }
            $rulesCreated += $item['remember'] ? 1 : 0;
            $items[$id] = $item['item'];
        }
        if ($errors !== []) {
            throw new AccountingValidationException($errors);
        }

        $this->entries->recordBatch($actor, $items, $now);
        $this->logger->info('Comptabilité : lignes de la banque validées', ['actor' => $actor->id(), 'count' => count($items), 'rules' => $rulesCreated]);

        return ['entries' => count($items), 'rules' => $rulesCreated];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $problem
     * @return array{item: array{year: int, input: array<string, mixed>, after: callable(AccountingEntry): void}, remember: bool}
     */
    private function item(User $actor, array $row, ImportLine $line, int $yearId, \DateTimeImmutable $now, array &$problem): array
    {
        $label = is_string($row['label'] ?? null) && trim($row['label']) !== '' ? $row['label'] : $line->label;
        $input = [
            'occurredOn' => $line->occurredOn->format('Y-m-d'),
            'label' => $label,
            'amount' => EuroText::plain($line->amountCents),
            'direction' => $line->direction->value,
            'category' => $row['category'] ?? null,
            'paymentMethod' => $row['paymentMethod'] ?? null,
            'voucherRef' => $row['voucherRef'] ?? null,
            'note' => $row['note'] ?? null,
            'memberUserId' => $row['memberUserId'] ?? null,
            'periodMonth' => $row['periodMonth'] ?? null,
        ];
        $occurrence = $this->occurrence($row['occurrence'] ?? null, $line, $problem);
        $pattern = $this->pattern($row, $line, $problem);
        $category = is_string($input['category']) ? EntryCategory::tryFrom($input['category']) : null;
        $method = is_string($input['paymentMethod']) ? PaymentMethod::tryFrom($input['paymentMethod']) : null;

        $after = function (AccountingEntry $entry) use ($actor, $line, $occurrence, $pattern, $category, $method, $now): void {
            $locked = $this->imports->lockLine($line->id);
            if ($locked === null || !$this->imports->decide($line->id, ImportLine::VALIDATED, $entry->id, $now)) {
                throw new AccountingValidationException(["{$line->id}.line" => 'Cette ligne a déjà été traitée.']);
            }
            if ($occurrence !== null && !$this->occurrences->decide($occurrence[0], $occurrence[1], 'confirmee', $entry->id, $actor->id(), $now)) {
                throw new AccountingValidationException(["{$line->id}.occurrence" => 'Cette échéance est déjà traitée.']);
            }
            if ($pattern !== null && $category !== null && $method !== null) {
                $this->rules->remember($pattern, $line->direction, $category, $method, $actor->id(), $now);
            }
        };

        return ['item' => ['year' => $yearId, 'input' => $input, 'after' => $after], 'remember' => $pattern !== null];
    }

    /**
     * @param array<string, string> $problem
     * @return array{0: int, 1: \DateTimeImmutable}|null
     */
    private function occurrence(mixed $raw, ImportLine $line, array &$problem): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $errors = [];
        if (is_string($raw) && preg_match('/\A([1-9][0-9]{0,9})\|([0-9]{4}-[0-9]{2}-01)\z/D', $raw, $m) === 1) {
            $template = $this->templates->find((int) $m[1]);
            $period = EntryInputPolicy::date($m[2], 'occurrence', $errors);
            if ($template !== null && $template->isActive && $period !== null && $this->schedule->isScheduled($template, $period) && $template->direction === $line->direction && ($template->amountCents === null || $template->amountCents === $line->amountCents)) {
                return [$template->id, $period];
            }
        }
        $problem['occurrence'] = 'Cette échéance de modèle ne correspond pas à la ligne.';

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $problem
     */
    private function pattern(array $row, ImportLine $line, array &$problem): ?string
    {
        if (($row['remember'] ?? false) !== true && ($row['remember'] ?? null) !== '1') {
            return null;
        }
        $custom = $row['rulePattern'] ?? null;
        $pattern = is_string($custom) && trim($custom) !== '' ? LabelNormalizer::normalize($custom) : LabelNormalizer::defaultPattern($line->label);
        if ($pattern === null || mb_strlen($pattern) < LabelNormalizer::PATTERN_MIN || mb_strlen($pattern) > LabelNormalizer::PATTERN_MAX || !str_contains(LabelNormalizer::normalize($line->label), $pattern)) {
            $problem['rulePattern'] = 'Ce libellé est trop court ou trop variable pour être retenu : choisissez un motif d\'au moins ' . LabelNormalizer::PATTERN_MIN . ' lettres tiré du libellé.';

            return null;
        }

        return $pattern;
    }
}
