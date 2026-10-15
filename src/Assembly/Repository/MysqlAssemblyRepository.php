<?php

declare(strict_types=1);

namespace App\Assembly\Repository;

use App\Assembly\Entity\Assembly;
use App\Assembly\Entity\AssemblyKind;
use App\Assembly\Entity\AssemblyStatus;
use App\Assembly\Exception\AssemblyLockedException;
use App\Assembly\Service\AssemblyDraft;

/**
 * Le gel d'un compte rendu validé ne repose sur aucun trigger (privilège SUPER souvent refusé en mutualisé) : toute écriture de
 * brouillon porte `status = 'brouillon' AND revision = ?` dans son WHERE, et personne n'écrit jamais sur `status = 'valide'`.
 */
final class MysqlAssemblyRepository implements AssemblyRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private const MYSQL_DUPLICATE_KEY = 1062;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insert(AssemblyKind $kind, \DateTimeImmutable $heldOn, int $createdBy, \DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO assemblies (kind, held_on, created_by_user_id, created_at, updated_at)
             VALUES (:kind, :held_on, :created_by, :created_at, :updated_at)'
        );
        $statement->execute([
            'kind' => $kind->value,
            'held_on' => $heldOn->format('Y-m-d'),
            'created_by' => $createdBy,
            'created_at' => $now->format(self::DATE_FORMAT),
            'updated_at' => $now->format(self::DATE_FORMAT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?Assembly
    {
        $statement = $this->pdo->prepare('SELECT * FROM assemblies WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function lock(int $id): ?AssemblyLock
    {
        $statement = $this->pdo->prepare(
            'SELECT status, revision, version,
                    EXISTS (SELECT 1 FROM assemblies next WHERE next.previous_id = assemblies.id) AS has_successor
             FROM assemblies WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : new AssemblyLock(AssemblyStatus::from($row['status']), (int) $row['revision'], (int) $row['version'], (int) $row['has_successor'] === 1);
    }

    public function updateDraftHeader(int $id, int $expectedRevision, AssemblyDraft $draft, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE assemblies
             SET kind = :kind, held_on = :held_on, location = :location, opened_at = :opened_at, closed_at = :closed_at,
                 quorum_reached = :quorum_reached, moral_report = :moral_report, financial_report = :financial_report,
                 other_business = :other_business, updated_at = :updated_at, revision = revision + 1
             WHERE id = :id AND status = 'brouillon' AND revision = :revision"
        );
        $statement->execute([
            'kind' => $draft->kind->value,
            'held_on' => $draft->heldOn->format('Y-m-d'),
            'location' => $draft->location,
            'opened_at' => $draft->openedAt,
            'closed_at' => $draft->closedAt,
            'quorum_reached' => $draft->quorumReached === null ? null : (int) $draft->quorumReached,
            'moral_report' => $draft->moralReport,
            'financial_report' => $draft->financialReport,
            'other_business' => $draft->otherBusiness,
            'updated_at' => $now->format(self::DATE_FORMAT),
            'id' => $id,
            'revision' => $expectedRevision,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markValidated(int $id, int $expectedRevision, int $actorId, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE assemblies
             SET status = 'valide', validated_by_user_id = :actor, validated_at = :validated_at, updated_at = :updated_at, revision = revision + 1
             WHERE id = :id AND status = 'brouillon' AND revision = :revision"
        );
        $statement->execute([
            'actor' => $actorId,
            'validated_at' => $now->format(self::DATE_FORMAT),
            'updated_at' => $now->format(self::DATE_FORMAT),
            'id' => $id,
            'revision' => $expectedRevision,
        ]);

        return $statement->rowCount() === 1;
    }

    public function insertCorrection(int $sourceId, string $reason, int $actorId, \DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO assemblies (kind, held_on, location, opened_at, closed_at, quorum_reached, moral_report, financial_report,
                                     other_business, version, previous_id, correction_reason, created_by_user_id, created_at, updated_at)
             SELECT kind, held_on, location, opened_at, closed_at, quorum_reached, moral_report, financial_report,
                    other_business, version + 1, id, :reason, :actor, :created_at, :updated_at
             FROM assemblies WHERE id = :id AND status = 'valide'"
        );
        try {
            $statement->execute([
                'reason' => $reason,
                'actor' => $actorId,
                'created_at' => $now->format(self::DATE_FORMAT),
                'updated_at' => $now->format(self::DATE_FORMAT),
                'id' => $sourceId,
            ]);
        } catch (\PDOException $e) {
            // UNIQUE(previous_id) : une version validée n'a qu'un successeur, la seconde correction concurrente perd.
            if (($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY) {
                throw new AssemblyLockedException();
            }
            throw $e;
        }
        if ($statement->rowCount() !== 1) {
            throw new AssemblyLockedException();
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteDraft(int $id): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM assemblies WHERE id = :id AND status = 'brouillon'");
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function listHeads(): array
    {
        $rows = $this->pdo->query(
            'SELECT a.* FROM assemblies a LEFT JOIN assemblies next ON next.previous_id = a.id
             WHERE next.id IS NULL ORDER BY a.held_on DESC, a.id DESC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function successorOf(int $id): ?Assembly
    {
        $statement = $this->pdo->prepare('SELECT * FROM assemblies WHERE previous_id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Assembly
    {
        return new Assembly(
            (int) $row['id'],
            AssemblyKind::from($row['kind']),
            new \DateTimeImmutable($row['held_on']),
            $row['location'],
            $this->hour($row['opened_at']),
            $this->hour($row['closed_at']),
            $row['quorum_reached'] === null ? null : (int) $row['quorum_reached'] === 1,
            $row['moral_report'],
            $row['financial_report'],
            $row['other_business'],
            AssemblyStatus::from($row['status']),
            (int) $row['version'],
            $row['previous_id'] === null ? null : (int) $row['previous_id'],
            $row['correction_reason'],
            (int) $row['revision'],
            new \DateTimeImmutable($row['created_at']),
            $row['validated_at'] === null ? null : new \DateTimeImmutable($row['validated_at']),
        );
    }

    /** `18:30:00` → `18:30`. */
    private function hour(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
