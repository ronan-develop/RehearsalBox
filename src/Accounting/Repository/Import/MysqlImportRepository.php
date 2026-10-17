<?php

declare(strict_types=1);

namespace App\Accounting\Repository\Import;

use App\Accounting\Entity\Direction;
use App\Accounting\Import\ImportLine;
use App\Accounting\Import\Parse\CsvProfile;
use App\Accounting\Import\Parse\ParsedLine;

final class MysqlImportRepository implements ImportRepositoryInterface
{
    private const FORMAT = 'Y-m-d H:i:s';
    private const DUPLICATE_KEY = 1062;
    private const COLUMNS = 'id, batch_id, record_number, occurred_on, label, amount_cents, direction, status, entry_id, created_at';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function profile(): ?CsvProfile
    {
        $statement = $this->pdo->query('SELECT * FROM accounting_import_profile WHERE id = 1');
        $row = $statement === false ? false : $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : new CsvProfile(
            (string) $row['delimiter_char'],
            (string) $row['encoding'],
            (int) $row['skip_rows'],
            (int) $row['date_column'],
            (int) $row['label_column'],
            (string) $row['amount_mode'],
            (int) $row['amount_column'],
            (int) $row['debit_column'],
            (int) $row['credit_column'],
            (string) $row['decimal_separator'],
            (string) $row['date_format'],
        );
    }

    public function saveProfile(CsvProfile $profile, int $actorId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO accounting_import_profile
                (id, delimiter_char, encoding, skip_rows, date_column, label_column, amount_mode, amount_column, debit_column, credit_column, decimal_separator, date_format, updated_by_user_id, updated_at)
             VALUES (1, :delimiter, :encoding, :skip, :date, :label, :mode, :amount, :debit, :credit, :decimal, :format, :actor, :now)
             ON DUPLICATE KEY UPDATE delimiter_char = VALUES(delimiter_char), encoding = VALUES(encoding), skip_rows = VALUES(skip_rows), date_column = VALUES(date_column),
                label_column = VALUES(label_column), amount_mode = VALUES(amount_mode), amount_column = VALUES(amount_column), debit_column = VALUES(debit_column),
                credit_column = VALUES(credit_column), decimal_separator = VALUES(decimal_separator), date_format = VALUES(date_format),
                updated_by_user_id = VALUES(updated_by_user_id), updated_at = VALUES(updated_at)'
        )->execute([
            'delimiter' => $profile->delimiter, 'encoding' => $profile->encoding, 'skip' => $profile->skipRows, 'date' => $profile->dateColumn,
            'label' => $profile->labelColumn, 'mode' => $profile->amountMode, 'amount' => $profile->amountColumn, 'debit' => $profile->debitColumn,
            'credit' => $profile->creditColumn, 'decimal' => $profile->decimalSeparator, 'format' => $profile->dateFormat, 'actor' => $actorId,
            'now' => $now->format(self::FORMAT),
        ]);
    }

    public function openBatch(string $fileSha256, int $actorId, \DateTimeImmutable $now): ?int
    {
        try {
            $this->pdo->prepare('INSERT INTO accounting_import_batches (file_sha256, staged_count, duplicate_count, error_count, uploaded_by_user_id, uploaded_at) VALUES (:sha, 0, 0, 0, :actor, :now)')
                ->execute(['sha' => $fileSha256, 'actor' => $actorId, 'now' => $now->format(self::FORMAT)]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === self::DUPLICATE_KEY) {
                return null;
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function closeBatch(int $batchId, int $staged, int $duplicates, int $errors): void
    {
        $this->pdo->prepare('UPDATE accounting_import_batches SET staged_count = :staged, duplicate_count = :duplicates, error_count = :errors WHERE id = :id')
            ->execute(['staged' => $staged, 'duplicates' => $duplicates, 'errors' => $errors, 'id' => $batchId]);
    }

    public function claimHash(string $lineHash, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare('INSERT IGNORE INTO accounting_import_hashes (line_hash, created_at) VALUES (:hash, :now)');
        $statement->execute(['hash' => $lineHash, 'now' => $now->format(self::FORMAT)]);

        return $statement->rowCount() === 1;
    }

    public function addLine(int $batchId, ParsedLine $line, \DateTimeImmutable $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO accounting_import_lines (batch_id, record_number, occurred_on, label, amount_cents, direction, created_at)
             VALUES (:batch, :record, :date, :label, :amount, :direction, :now)'
        )->execute([
            'batch' => $batchId, 'record' => $line->recordNumber, 'date' => $line->date->format('Y-m-d'), 'label' => $line->label,
            'amount' => $line->amountCents, 'direction' => $line->direction->value, 'now' => $now->format(self::FORMAT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function pending(int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM accounting_import_lines WHERE status = 'en_attente' ORDER BY occurred_on, id LIMIT " . max(1, $limit));
        $statement->execute();

        return array_map(self::hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function lockLine(int $id): ?ImportLine
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM accounting_import_lines WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function decide(int $id, string $status, ?int $entryId, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare("UPDATE accounting_import_lines SET status = :status, entry_id = :entry, decided_at = :now WHERE id = :id AND status = 'en_attente'");
        $statement->execute(['status' => $status, 'entry' => $entryId, 'now' => $now->format(self::FORMAT), 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function purge(\DateTimeImmutable $before, \DateTimeImmutable $keepHashesBefore): int
    {
        $lines = $this->pdo->prepare('DELETE FROM accounting_import_lines WHERE created_at < :before');
        $lines->execute(['before' => $before->format(self::FORMAT)]);
        $this->pdo->prepare('DELETE FROM accounting_import_hashes WHERE created_at < :before')->execute(['before' => $keepHashesBefore->format(self::FORMAT)]);
        $this->pdo->prepare('DELETE b FROM accounting_import_batches b LEFT JOIN accounting_import_lines l ON l.batch_id = b.id WHERE b.uploaded_at < :before AND l.id IS NULL')->execute(['before' => $keepHashesBefore->format(self::FORMAT)]);

        return $lines->rowCount();
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): ImportLine
    {
        return new ImportLine(
            (int) $row['id'],
            (int) $row['batch_id'],
            (int) $row['record_number'],
            new \DateTimeImmutable((string) $row['occurred_on']),
            (string) $row['label'],
            (int) $row['amount_cents'],
            Direction::from((string) $row['direction']),
            (string) $row['status'],
            $row['entry_id'] === null ? null : (int) $row['entry_id'],
            new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
