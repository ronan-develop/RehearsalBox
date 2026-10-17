<?php

declare(strict_types=1);

namespace App\Accounting\Repository\Import;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Import\Rules\LabelRule;

final class MysqlLabelRuleRepository implements LabelRuleRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, pattern, direction, category, payment_method FROM accounting_label_rules ORDER BY id DESC LIMIT 500');

        return $statement === false ? [] : array_map(self::hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function remember(string $pattern, Direction $direction, EntryCategory $category, PaymentMethod $method, int $actorId, \DateTimeImmutable $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO accounting_label_rules (pattern, direction, category, payment_method, created_by_user_id, created_at)
             VALUES (:pattern, :direction, :category, :method, :actor, :now)
             ON DUPLICATE KEY UPDATE category = VALUES(category), payment_method = VALUES(payment_method), id = LAST_INSERT_ID(id)'
        )->execute(['pattern' => $pattern, 'direction' => $direction->value, 'category' => $category->value, 'method' => $method->value, 'actor' => $actorId, 'now' => $now->format('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?LabelRule
    {
        $statement = $this->pdo->prepare('SELECT id, pattern, direction, category, payment_method FROM accounting_label_rules WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function retarget(int $id, EntryCategory $category, PaymentMethod $method): bool
    {
        $this->pdo->prepare('UPDATE accounting_label_rules SET category = :category, payment_method = :method WHERE id = :id')
            ->execute(['category' => $category->value, 'method' => $method->value, 'id' => $id]);

        return $this->find($id) !== null;
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM accounting_label_rules WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): LabelRule
    {
        return new LabelRule((int) $row['id'], (string) $row['pattern'], Direction::from((string) $row['direction']), EntryCategory::from((string) $row['category']), PaymentMethod::from((string) $row['payment_method']));
    }
}
