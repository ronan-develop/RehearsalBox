<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Database\TransactionRunner;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Messaging\Service\ConversationReader;
use App\Messaging\Service\ConversationService;
use App\Messaging\Service\ConversationThreadBuilder;
use Symfony\Component\Clock\MockClock;

/**
 * Décor des tests de services de la messagerie : horloge fixe, personnes, groupes, et le couple écriture (service) /
 * lecture (lecteur). À utiliser dans une classe qui étend RepositoryTestCase et appelle setUpWorld() dans setUp().
 */
trait ConversationWorld
{
    private MockClock $clock;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;
    private ConversationService $service;
    private ConversationReader $reader;

    private function setUpWorld(): void
    {
        $this->clock = new MockClock('2026-10-04 12:00:00');
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $conversations = new MysqlConversationRepository($this->pdo);
        $messages = new MysqlConversationMessageRepository($this->pdo);
        $presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->service = new ConversationService($conversations, $messages, $presence, $this->groups, new TransactionRunner($this->pdo), $this->clock);
        $this->reader = $this->readerFor($conversations, $messages, $presence);
    }

    private function readerFor(MysqlConversationRepository $conversations, MysqlConversationMessageRepository $messages, MysqlConversationPresenceRepository $presence): ConversationReader
    {
        $access = new \App\Messaging\Service\ConversationAccess($conversations, $this->groups);

        return new ConversationReader(
            $access,
            new ConversationThreadBuilder($conversations, $messages, $presence, $this->groups, $this->clock),
            $conversations,
            $messages,
            $presence,
            $this->clock,
        );
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', 'hash', $name, UserRole::Musicien, true, 0, null));
    }

    private function group(string $name, ?string $color, User ...$members): Group
    {
        $group = $this->groups->save(new Group(0, $name, null, $color, strtolower($name) . '@rehearsalbox.test'));
        foreach ($members as $member) {
            $this->groups->addMember($group->id(), $member->id());
        }

        return $group;
    }

    /** @return array{User, User, Group, Group} Alice (Alpha) et Bob (Beta) */
    private function world(): array
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');

        return [$alice, $bob, $this->group('Alpha', '#aa0000', $alice), $this->group('Beta', '#0000aa', $bob)];
    }

    /** @param list<\App\Messaging\Entity\ConversationMessage> $messages */
    private function lastOf(array $messages): \App\Messaging\Entity\ConversationMessage
    {
        return $messages[array_key_last($messages)];
    }
}
