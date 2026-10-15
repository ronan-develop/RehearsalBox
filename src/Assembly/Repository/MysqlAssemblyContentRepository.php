<?php

declare(strict_types=1);

namespace App\Assembly\Repository;

use App\Assembly\Entity\AgendaItem;
use App\Assembly\Entity\Attendance;
use App\Assembly\Entity\AssemblyContent;
use App\Assembly\Entity\Attendee;
use App\Assembly\Entity\BoardMember;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Entity\Resolution;
use App\Assembly\Entity\ResolutionOutcome;

/** Tables filles d'un compte rendu (#361) : lecture, remplacement complet et copie entre versions. Pas de transaction ici, l'appelant la gère. */
final class MysqlAssemblyContentRepository implements AssemblyContentRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function load(int $assemblyId): AssemblyContent
    {
        $board = [];
        foreach ($this->rowsOf('SELECT post, user_id, full_name FROM assembly_board_members WHERE assembly_id = :assembly_id', $assemblyId) as $row) {
            $post = BoardPost::from((string) $row['post']);
            $board[$post->value] = new BoardMember($post, (int) $row['user_id'], (string) $row['full_name']);
        }

        $attendees = [];
        foreach ($this->rowsOf('SELECT user_id, full_name, attendance, proxy_holder FROM assembly_attendees WHERE assembly_id = :assembly_id ORDER BY position', $assemblyId) as $row) {
            $attendees[] = new Attendee(
                $row['user_id'] !== null ? (int) $row['user_id'] : null,
                (string) $row['full_name'],
                Attendance::from((string) $row['attendance']),
                $row['proxy_holder'] !== null ? (string) $row['proxy_holder'] : null,
            );
        }

        $agenda = [];
        foreach ($this->rowsOf('SELECT title, discussion FROM assembly_agenda_items WHERE assembly_id = :assembly_id ORDER BY position', $assemblyId) as $row) {
            $agenda[] = new AgendaItem(
                (string) $row['title'],
                $row['discussion'] !== null ? (string) $row['discussion'] : null,
            );
        }

        $resolutions = [];
        foreach ($this->rowsOf('SELECT text, votes_for, votes_against, votes_abstain, outcome FROM assembly_resolutions WHERE assembly_id = :assembly_id ORDER BY position', $assemblyId) as $row) {
            $resolutions[] = new Resolution(
                (string) $row['text'],
                (int) $row['votes_for'],
                (int) $row['votes_against'],
                (int) $row['votes_abstain'],
                $row['outcome'] !== null ? ResolutionOutcome::from((string) $row['outcome']) : null,
            );
        }

        return new AssemblyContent($board, $attendees, $agenda, $resolutions);
    }

    public function replace(int $assemblyId, AssemblyContent $content): void
    {
        $this->pdo->prepare('DELETE FROM assembly_board_members WHERE assembly_id = :assembly_id')->execute(['assembly_id' => $assemblyId]);
        $this->pdo->prepare('DELETE FROM assembly_attendees WHERE assembly_id = :assembly_id')->execute(['assembly_id' => $assemblyId]);
        $this->pdo->prepare('DELETE FROM assembly_agenda_items WHERE assembly_id = :assembly_id')->execute(['assembly_id' => $assemblyId]);
        $this->pdo->prepare('DELETE FROM assembly_resolutions WHERE assembly_id = :assembly_id')->execute(['assembly_id' => $assemblyId]);

        $insertBoard = $this->pdo->prepare(
            'INSERT INTO assembly_board_members (assembly_id, post, user_id, full_name)
             VALUES (:assembly_id, :post, :user_id, :full_name)'
        );
        foreach ($content->board as $member) {
            $insertBoard->execute([
                'assembly_id' => $assemblyId,
                'post' => $member->post->value,
                'user_id' => $member->userId,
                'full_name' => $member->name,
            ]);
        }

        $insertAttendee = $this->pdo->prepare(
            'INSERT INTO assembly_attendees (assembly_id, position, user_id, full_name, attendance, proxy_holder)
             VALUES (:assembly_id, :position, :user_id, :full_name, :attendance, :proxy_holder)'
        );
        foreach ($content->attendees as $position => $attendee) {
            $insertAttendee->execute([
                'assembly_id' => $assemblyId,
                'position' => $position,
                'user_id' => $attendee->userId,
                'full_name' => $attendee->name,
                'attendance' => $attendee->attendance->value,
                'proxy_holder' => $attendee->proxyHolder,
            ]);
        }

        $insertAgendaItem = $this->pdo->prepare(
            'INSERT INTO assembly_agenda_items (assembly_id, position, title, discussion)
             VALUES (:assembly_id, :position, :title, :discussion)'
        );
        foreach ($content->agenda as $position => $item) {
            $insertAgendaItem->execute([
                'assembly_id' => $assemblyId,
                'position' => $position,
                'title' => $item->title,
                'discussion' => $item->discussion,
            ]);
        }

        $insertResolution = $this->pdo->prepare(
            'INSERT INTO assembly_resolutions (assembly_id, position, text, votes_for, votes_against, votes_abstain, outcome)
             VALUES (:assembly_id, :position, :text, :votes_for, :votes_against, :votes_abstain, :outcome)'
        );
        foreach ($content->resolutions as $position => $resolution) {
            $insertResolution->execute([
                'assembly_id' => $assemblyId,
                'position' => $position,
                'text' => $resolution->text,
                'votes_for' => $resolution->for,
                'votes_against' => $resolution->against,
                'votes_abstain' => $resolution->abstain,
                'outcome' => $resolution->outcome?->value,
            ]);
        }
    }

    public function copy(int $fromId, int $toId): void
    {
        $this->pdo->prepare(
            'INSERT INTO assembly_board_members (assembly_id, post, user_id, full_name)
             SELECT :to_id, post, user_id, full_name FROM assembly_board_members WHERE assembly_id = :from_id'
        )->execute(['to_id' => $toId, 'from_id' => $fromId]);

        $this->pdo->prepare(
            'INSERT INTO assembly_attendees (assembly_id, position, user_id, full_name, attendance, proxy_holder)
             SELECT :to_id, position, user_id, full_name, attendance, proxy_holder FROM assembly_attendees WHERE assembly_id = :from_id'
        )->execute(['to_id' => $toId, 'from_id' => $fromId]);

        $this->pdo->prepare(
            'INSERT INTO assembly_agenda_items (assembly_id, position, title, discussion)
             SELECT :to_id, position, title, discussion FROM assembly_agenda_items WHERE assembly_id = :from_id'
        )->execute(['to_id' => $toId, 'from_id' => $fromId]);

        $this->pdo->prepare(
            'INSERT INTO assembly_resolutions (assembly_id, position, text, votes_for, votes_against, votes_abstain, outcome)
             SELECT :to_id, position, text, votes_for, votes_against, votes_abstain, outcome FROM assembly_resolutions WHERE assembly_id = :from_id'
        )->execute(['to_id' => $toId, 'from_id' => $fromId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $sql, int $assemblyId): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['assembly_id' => $assemblyId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
