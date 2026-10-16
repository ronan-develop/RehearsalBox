<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Entity\Periodicity;
use App\Accounting\Entity\RecurringTemplate;
use App\Accounting\Service\Recurring\TemplateDraft;

final class MysqlRecurringTemplateRepository implements RecurringTemplateRepositoryInterface
{
    private const COLUMNS = 'id, label, direction, category, payment_method, amount_cents, voucher_ref, periodicity, due_day, first_month, is_active';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insert(TemplateDraft $draft, int $actorId, \DateTimeImmutable $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO accounting_recurring_templates
                (label, direction, category, payment_method, amount_cents, voucher_ref, periodicity, due_day, first_month, is_active, created_by_user_id, created_at)
             VALUES (:label, :direction, :category, :method, :amount, :voucher, :periodicity, :day, :month, :active, :actor, :now)'
        )->execute(self::params($draft) + ['actor' => $actorId, 'now' => $now->format('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, TemplateDraft $draft): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE accounting_recurring_templates SET label = :label, direction = :direction, category = :category, payment_method = :method,
                amount_cents = :amount, voucher_ref = :voucher, periodicity = :periodicity, due_day = :day, first_month = :month, is_active = :active
             WHERE id = :id'
        );
        $statement->execute(self::params($draft) + ['id' => $id]);

        // MySQL ne compte pas une mise à jour sans changement : on vérifie l'existence.
        return $statement->rowCount() > 0 || $this->find($id) !== null;
    }

    public function find(int $id): ?RecurringTemplate
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM accounting_recurring_templates WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function listAll(int $limit): array
    {
        $statement = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM accounting_recurring_templates ORDER BY is_active DESC, id DESC LIMIT ' . max(1, $limit));

        return $statement === false ? [] : array_map(self::hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private static function params(TemplateDraft $draft): array
    {
        return [
            'label' => $draft->label,
            'direction' => $draft->direction->value,
            'category' => $draft->category->value,
            'method' => $draft->paymentMethod->value,
            'amount' => $draft->amountCents,
            'voucher' => $draft->voucherRef,
            'periodicity' => $draft->periodicity->value,
            'day' => $draft->dueDay,
            'month' => $draft->firstMonth->format('Y-m-d'),
            'active' => $draft->isActive ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): RecurringTemplate
    {
        return new RecurringTemplate(
            (int) $row['id'],
            (string) $row['label'],
            Direction::from((string) $row['direction']),
            EntryCategory::from((string) $row['category']),
            PaymentMethod::from((string) $row['payment_method']),
            $row['amount_cents'] === null ? null : (int) $row['amount_cents'],
            $row['voucher_ref'] === null ? null : (string) $row['voucher_ref'],
            Periodicity::from((string) $row['periodicity']),
            (int) $row['due_day'],
            new \DateTimeImmutable((string) $row['first_month']),
            (bool) $row['is_active'],
        );
    }
}
