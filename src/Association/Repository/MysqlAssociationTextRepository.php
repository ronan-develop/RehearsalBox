<?php

declare(strict_types=1);

namespace App\Association\Repository;

use App\Association\Entity\AssociationText;
use App\Association\Exception\AssociationTextValidationException;

final class MysqlAssociationTextRepository implements AssociationTextRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private const MYSQL_DUPLICATE_KEY = 1062;

    private const SELECT = 'SELECT t.id, t.title, t.body, t.revision, t.updated_at, u.display_name
                            FROM association_texts t JOIN users u ON u.id = t.updated_by_user_id';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insert(string $title, string $body, int $actorId, \DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO association_texts (title, body, created_by_user_id, created_at, updated_by_user_id, updated_at)
             VALUES (:title, :body, :actor, :now, :actor_again, :now_again)'
        );
        $this->execute($statement, [
            'title' => $title,
            'body' => $body,
            'actor' => $actorId,
            'now' => $now->format(self::DATE_FORMAT),
            'actor_again' => $actorId,
            'now_again' => $now->format(self::DATE_FORMAT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?AssociationText
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE t.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function listAll(): array
    {
        $rows = $this->pdo->query(self::SELECT . ' ORDER BY t.title')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function update(int $id, int $expectedRevision, string $title, string $body, int $actorId, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE association_texts
             SET title = :title, body = :body, updated_by_user_id = :actor, updated_at = :now, revision = revision + 1
             WHERE id = :id AND revision = :revision'
        );
        $this->execute($statement, [
            'title' => $title,
            'body' => $body,
            'actor' => $actorId,
            'now' => $now->format(self::DATE_FORMAT),
            'id' => $id,
            'revision' => $expectedRevision,
        ]);

        return $statement->rowCount() === 1;
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM association_texts WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws AssociationTextValidationException clé unique du titre
     */
    private function execute(\PDOStatement $statement, array $params): void
    {
        try {
            $statement->execute($params);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY) {
                throw new AssociationTextValidationException(['title' => 'Un texte porte déjà ce titre.']);
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AssociationText
    {
        return new AssociationText(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['body'],
            (int) $row['revision'],
            new \DateTimeImmutable((string) $row['updated_at']),
            (string) $row['display_name'],
        );
    }
}
