<?php

declare(strict_types=1);

namespace App\Accounting\Import\Suggest;

use App\Account\Entity\User;
use App\Accounting\Import\ImportLine;
use App\Accounting\Repository\EntryRepositoryInterface;
use App\Accounting\Repository\Import\ImportRepositoryInterface;
use App\Accounting\Repository\Import\LabelRuleRepositoryInterface;
use App\Accounting\Repository\MemberDuesRepositoryInterface;
use App\Accounting\Repository\RecurringOccurrenceRepositoryInterface;
use App\Accounting\Repository\RecurringTemplateRepositoryInterface;
use App\Security\AdminAccess;

/** Lignes en attente avec leurs propositions (#362), réservé aux administrateurs actifs : lecture seule, rien n'est décidé ici. */
final class ImportReviewService
{
    private const LINE_LIMIT = 500;
    private const TEMPLATE_LIMIT = 200;
    private const MEMBER_LIMIT = 300;

    public function __construct(
        private readonly AdminAccess $access,
        private readonly ImportRepositoryInterface $imports,
        private readonly RecurringTemplateRepositoryInterface $templates,
        private readonly RecurringOccurrenceRepositoryInterface $occurrences,
        private readonly MemberDuesRepositoryInterface $members,
        private readonly EntryRepositoryInterface $entries,
        private readonly LabelRuleRepositoryInterface $rules,
        private readonly LineSuggester $suggester = new LineSuggester(),
    ) {
    }

    /** @return array{lines: list<ImportLine>, suggestions: array<int, Suggestion>} */
    public function review(User $actor): array
    {
        $this->access->assertAdmin($actor);
        $lines = $this->imports->pending(self::LINE_LIMIT);
        if ($lines === []) {
            return ['lines' => [], 'suggestions' => []];
        }
        $templates = array_values(array_filter($this->templates->listAll(self::TEMPLATE_LIMIT), static fn ($t): bool => $t->isActive));
        $decided = $this->occurrences->decidedFor(array_map(static fn ($t): int => $t->id, $templates));
        $from = $lines[0]->occurredOn->modify('first day of this month');
        $to = $lines[count($lines) - 1]->occurredOn->modify('last day of this month');
        $paid = $this->entries->paidMonths($from, $to);

        return ['lines' => $lines, 'suggestions' => $this->suggester->suggest($lines, $templates, $decided, $this->members->listActive(self::MEMBER_LIMIT), $paid, $this->rules->all())];
    }
}
