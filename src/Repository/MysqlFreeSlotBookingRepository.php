<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\FreeSlotBookingStatus;
use App\Entity\FreeSlotBooking;
use App\Entity\Requester;
use App\Entity\TimeRange;
use App\Repository\Contract\FreeSlotBookingRepositoryInterface;

final class MysqlFreeSlotBookingRepository implements FreeSlotBookingRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(Requester $requester, \DateTimeImmutable $date, TimeRange $range, ?string $reason): FreeSlotBooking
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO free_slot_bookings (group_id, booked_by_user_id, booking_date, start_time, end_time, reason)
             VALUES (:group_id, :user_id, :booking_date, :start_time, :end_time, :reason)'
        );
        $statement->execute([
            'group_id' => $requester->groupId(),
            'user_id' => $requester->userId(),
            'booking_date' => $date->format('Y-m-d'),
            'start_time' => $range->start(),
            'end_time' => $range->end(),
            'reason' => $reason,
        ]);

        $created = $this->findById((int) $this->pdo->lastInsertId());
        \assert($created !== null);

        return $created;
    }

    public function findById(int $id): ?FreeSlotBooking
    {
        $statement = $this->pdo->prepare('SELECT * FROM free_slot_bookings WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findOverlapping(\DateTimeImmutable $date, TimeRange $range): array
    {
        return $this->many(
            "SELECT * FROM free_slot_bookings
             WHERE booking_date = :booking_date AND status IN ('en_attente', 'validee')
               AND start_time < :end_time AND end_time > :start_time
             ORDER BY start_time, id",
            ['booking_date' => $date->format('Y-m-d'), 'start_time' => $range->start(), 'end_time' => $range->end()],
        );
    }

    public function countUpcomingFor(int $groupId, \DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM free_slot_bookings
             WHERE group_id = :group_id AND status IN ('en_attente', 'validee')
               AND (booking_date > :today OR (booking_date = :today_again AND end_time > :time))"
        );
        $statement->execute(['group_id' => $groupId, 'today' => $now->format('Y-m-d'), 'today_again' => $now->format('Y-m-d'), 'time' => $now->format('H:i:s')]);

        return (int) $statement->fetchColumn();
    }

    public function decide(int $id, FreeSlotBookingStatus $decision, int $adminId, ?string $note, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE free_slot_bookings
             SET status = :status, decided_by_user_id = :admin_id, decided_at = :now, decision_note = :note
             WHERE id = :id AND status = 'en_attente'"
        );
        $statement->execute(['status' => $decision->value, 'admin_id' => $adminId, 'now' => $now->format(self::DATE_FORMAT), 'note' => $note, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function cancel(int $id, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE free_slot_bookings SET status = 'annulee'
             WHERE id = :id AND status IN ('en_attente', 'validee')
               AND (booking_date > :today OR (booking_date = :today_again AND start_time > :time))"
        );
        $statement->execute(['id' => $id, 'today' => $now->format('Y-m-d'), 'today_again' => $now->format('Y-m-d'), 'time' => $now->format('H:i:s')]);

        return $statement->rowCount() === 1;
    }

    public function findPending(): array
    {
        return $this->many("SELECT * FROM free_slot_bookings WHERE status = 'en_attente' ORDER BY booking_date, start_time, id", []);
    }

    public function findForGroup(int $groupId, \DateTimeImmutable $now): array
    {
        return $this->many(
            'SELECT * FROM free_slot_bookings WHERE group_id = :group_id AND booking_date >= :today ORDER BY booking_date, start_time, id',
            ['group_id' => $groupId, 'today' => $now->format('Y-m-d')],
        );
    }

    public function findValidatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->many(
            "SELECT * FROM free_slot_bookings WHERE status = 'validee' AND booking_date BETWEEN :from_date AND :to_date ORDER BY booking_date, start_time, id",
            ['from_date' => $from->format('Y-m-d'), 'to_date' => $to->format('Y-m-d')],
        );
    }

    /**
     * @param array<string, int|string> $parameters
     *
     * @return list<FreeSlotBooking>
     */
    private function many(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): FreeSlotBooking
    {
        return new FreeSlotBooking(
            (int) $row['id'],
            new Requester((int) $row['group_id'], (int) $row['booked_by_user_id']),
            new \DateTimeImmutable((string) $row['booking_date']),
            new TimeRange((string) $row['start_time'], (string) $row['end_time']),
            FreeSlotBookingStatus::from((string) $row['status']),
            $row['reason'] !== null ? (string) $row['reason'] : null,
            $row['decision_note'] !== null ? (string) $row['decision_note'] : null,
            new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
