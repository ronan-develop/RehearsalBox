<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupDocument;
use App\Repository\Contract\GroupDocumentRepositoryInterface;

final class MysqlGroupDocumentRepository implements GroupDocumentRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function findById(int $id): ?GroupDocument
    {
        $statement = $this->pdo->prepare('SELECT * FROM group_documents WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByGroup(int $groupId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM group_documents WHERE group_id = :group_id ORDER BY created_at DESC');
        $statement->execute(['group_id' => $groupId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countByGroup(int $groupId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM group_documents WHERE group_id = :group_id');
        $statement->execute(['group_id' => $groupId]);

        return (int) $statement->fetchColumn();
    }

    public function save(GroupDocument $document): GroupDocument
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO group_documents (group_id, original_name, stored_name, mime_type, size_bytes, uploaded_by_user_id)
             VALUES (:group_id, :original_name, :stored_name, :mime_type, :size_bytes, :uploaded_by_user_id)'
        );
        $statement->execute([
            'group_id' => $document->groupId(),
            'original_name' => $document->originalName(),
            'stored_name' => $document->storedName(),
            'mime_type' => $document->mimeType(),
            'size_bytes' => $document->sizeBytes(),
            'uploaded_by_user_id' => $document->uploadedByUserId(),
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM group_documents WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): GroupDocument
    {
        return new GroupDocument(
            id: (int) $row['id'],
            groupId: (int) $row['group_id'],
            originalName: (string) $row['original_name'],
            storedName: (string) $row['stored_name'],
            mimeType: (string) $row['mime_type'],
            sizeBytes: (int) $row['size_bytes'],
            uploadedByUserId: (int) $row['uploaded_by_user_id'],
        );
    }
}
