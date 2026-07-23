<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Group;
use App\Repository\Contract\GroupRepositoryInterface;

final class MysqlGroupRepository implements GroupRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function findById(int $id): ?Group
    {
        $statement = $this->pdo->prepare('SELECT * FROM `groups` WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findAll(): array
    {
        $rows = $this->pdo->query('SELECT * FROM `groups` ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function findByMember(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT g.* FROM `groups` g
             INNER JOIN group_user gu ON gu.group_id = g.id
             WHERE gu.user_id = :user_id
             ORDER BY g.name'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function save(Group $group): Group
    {
        if ($group->id() === 0) {
            $statement = $this->pdo->prepare(
                'INSERT INTO `groups` (name, genre, color_hex) VALUES (:name, :genre, :color_hex)'
            );
            $statement->execute([
                'name' => $group->name(),
                'genre' => $group->genre(),
                'color_hex' => $group->colorHex(),
            ]);

            return $this->findById((int) $this->pdo->lastInsertId());
        }

        $statement = $this->pdo->prepare(
            'UPDATE `groups` SET name = :name, genre = :genre, color_hex = :color_hex WHERE id = :id'
        );
        $statement->execute([
            'id' => $group->id(),
            'name' => $group->name(),
            'genre' => $group->genre(),
            'color_hex' => $group->colorHex(),
        ]);

        return $this->findById($group->id());
    }

    public function addMember(int $groupId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO group_user (group_id, user_id) VALUES (:group_id, :user_id)'
        );
        $statement->execute(['group_id' => $groupId, 'user_id' => $userId]);
    }

    public function removeMember(int $groupId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM group_user WHERE group_id = :group_id AND user_id = :user_id'
        );
        $statement->execute(['group_id' => $groupId, 'user_id' => $userId]);
    }

    public function isMember(int $groupId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM group_user WHERE group_id = :group_id AND user_id = :user_id'
        );
        $statement->execute(['group_id' => $groupId, 'user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Group
    {
        return new Group(
            id: (int) $row['id'],
            name: (string) $row['name'],
            genre: $row['genre'] !== null ? (string) $row['genre'] : null,
            colorHex: $row['color_hex'] !== null ? (string) $row['color_hex'] : null,
        );
    }
}
