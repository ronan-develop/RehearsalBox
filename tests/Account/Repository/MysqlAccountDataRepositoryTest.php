<?php

declare(strict_types=1);

namespace App\Tests\Account\Repository;

use App\Account\Repository\MysqlAccountDataRepository;
use App\Group\Entity\GroupUserRole;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Planning\Entity\Requester;
use App\Planning\Entity\TimeRange;
use App\Planning\Repository\MysqlFreeSlotBookingRepository;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use App\Tests\Support\TestMessageCipher;
use PHPUnit\Framework\Attributes\Test;

/** #431 : tout ce que l'application garde d'UNE personne, rien de ce qui appartient à une autre. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlAccountDataRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlAccountDataRepository $data;
    private int $alice;
    private int $bob;
    private int $alpha;
    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        [$this->alice, $this->bob] = [$alice->id(), $bob->id()];
        $this->alpha = $this->group('Alpha', $alice)->id();
        $this->beta = $this->group('Beta', $bob)->id();
        $this->groups->addMember($this->alpha, $bob->id(), GroupUserRole::Gestionnaire);
        $this->data = new MysqlAccountDataRepository($this->pdo, TestMessageCipher::make());
    }

    #[Test]
    public function testMembershipsListTheGroupsAndRolesOfThatPersonOnly(): void
    {
        self::assertSame([['groupId' => $this->alpha, 'group' => 'Alpha', 'role' => 'membre']], $this->data->memberships($this->alice));
        self::assertEqualsCanonicalizing(
            [['groupId' => $this->alpha, 'group' => 'Alpha', 'role' => 'gestionnaire'], ['groupId' => $this->beta, 'group' => 'Beta', 'role' => 'membre']],
            $this->data->memberships($this->bob),
        );
    }

    #[Test]
    public function testMessagesAreReturnedDecryptedAndOnlyThoseWrittenByThePerson(): void
    {
        $conversation = (new MysqlConversationRepository($this->pdo, TestMessageCipher::make()))->create($this->alpha, $this->beta, 'Répétition', $this->now, $this->alice);
        $messages = new MysqlConversationMessageRepository($this->pdo, TestMessageCipher::make());
        $messages->addMessage($conversation->id(), $this->alice, 'Salut, jeudi ?', $this->now);
        $messages->addMessage($conversation->id(), $this->bob, 'Message de Bob', $this->now->modify('+1 minute'));
        $messages->addMessage($conversation->id(), $this->alice, 'Alice a rejoint', $this->now->modify('+2 minutes'), true);

        $stored = (string) $this->pdo->query('SELECT body FROM conversation_messages WHERE author_id = ' . $this->alice . ' ORDER BY id LIMIT 1')->fetchColumn();
        self::assertStringNotContainsString('Salut', $stored, 'en base, le texte est chiffré');

        $exported = $this->data->messages($this->alice);

        self::assertCount(1, $exported, 'ni les messages des autres ni les lignes système');
        self::assertSame('Salut, jeudi ?', $exported[0]['text']);
        self::assertSame($conversation->id(), $exported[0]['conversationId']);
        self::assertSame($this->now->format('Y-m-d H:i:s'), $exported[0]['sentAt']);
    }

    #[Test]
    public function testBookingsAreThoseMadeByThePerson(): void
    {
        $bookings = new MysqlFreeSlotBookingRepository($this->pdo);
        $bookings->create(new Requester($this->alpha, $this->alice), new \DateTimeImmutable('2026-10-07'), new TimeRange('09:00:00', '10:00:00'), 'Enregistrement');
        $bookings->create(new Requester($this->alpha, $this->bob), new \DateTimeImmutable('2026-10-08'), new TimeRange('09:00:00', '10:00:00'), 'Autre');

        $exported = $this->data->bookings($this->alice);

        self::assertCount(1, $exported);
        self::assertEquals(['date' => '2026-10-07', 'start' => '09:00', 'end' => '10:00', 'status' => 'en_attente', 'reason' => 'Enregistrement', 'group' => 'Alpha'], array_diff_key($exported[0], ['createdAt' => 1]));
    }

    #[Test]
    public function testAPersonWithNothingGetsEmptyLists(): void
    {
        $nobody = $this->user('Nobody')->id();

        self::assertSame([], $this->data->memberships($nobody));
        self::assertSame([], $this->data->messages($nobody));
        self::assertSame([], $this->data->bookings($nobody));
        self::assertSame([], $this->data->slotRequests($nobody));
    }
}
