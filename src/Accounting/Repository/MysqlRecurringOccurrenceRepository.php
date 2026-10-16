<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

final class MysqlRecurringOccurrenceRepository implements RecurringOccurrenceRepositoryInterface
{
    private const MYSQL_DUPLICATE_KEY = 1062;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function decide(int $templateId, \DateTimeImmutable $period, string $status, ?int $entryId, int $actorId, \DateTimeImmutable $now): bool
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO accounting_recurring_occurrences (template_id, period, status, entry_id, decided_by_user_id, decided_at)
                 VALUES (:template, :period, :status, :entry, :actor, :now)'
            )->execute(['template' => $templateId, 'period' => $period->format('Y-m-d'), 'status' => $status, 'entry' => $entryId, 'actor' => $actorId, 'now' => $now->format('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function decidedFor(array $templateIds): array
    {
        if ($templateIds === []) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT template_id, period FROM accounting_recurring_occurrences WHERE template_id IN (' . implode(',', array_fill(0, count($templateIds), '?')) . ')');
        $statement->execute($templateIds);
        $decided = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $decided[$row['template_id'] . '|' . $row['period']] = true;
        }

        return $decided;
    }
}
