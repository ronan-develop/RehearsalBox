<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\GroupDocument;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupImpactRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Repository\MysqlConversationTrashRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Ce que la suppression d'un groupe emporterait avec lui (#224) : de quoi afficher une confirmation chiffrée. */
final class MysqlGroupImpactRepositoryTest extends RepositoryTestCase
{
    #[Test]
    public function testItCountsWhatEachGroupWouldTakeWithItWhenDeleted(): void
    {
        $groups = new MysqlGroupRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@example.test'));
        $gamma = $groups->save(new Group(0, 'Gamma', null, null, 'gamma@example.test'));
        $alice = $users->save(new User(0, 'alice@rehearsalbox.test', 'hash', 'Alice', UserRole::Musicien, true, 0, null));
        $bob = $users->save(new User(0, 'bob@rehearsalbox.test', 'hash', 'Bob', UserRole::Musicien, true, 0, null));
        $groups->addMember($alpha->id(), $alice->id());
        $groups->addMember($alpha->id(), $bob->id());
        $groups->addMember($beta->id(), $bob->id());

        $conversations = new MysqlConversationRepository($this->pdo);
        $conversations->create($alpha->id(), $beta->id(), null, new \DateTimeImmutable('2026-10-04 10:00:00'), $alice->id());
        $trashed = $conversations->create($beta->id(), $alpha->id(), null, new \DateTimeImmutable('2026-10-04 11:00:00'), $bob->id());
        (new MysqlConversationTrashRepository($this->pdo))->moveToTrash($trashed->id(), new \DateTimeImmutable('2026-10-04 12:00:00'));

        (new MysqlGroupDocumentRepository($this->pdo))->save(new GroupDocument(0, $alpha->id(), 'fiche.pdf', 'abc.pdf', 'application/pdf', 10, $alice->id()));

        $slot = (new MysqlRecurringSlotRepository($this->pdo))->save(new RecurringSlot(0, $beta->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        (new MysqlSlotExceptionRepository($this->pdo))->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $alpha->id(), $alice->id(), null);

        $counts = (new MysqlGroupImpactRepository($this->pdo))->countsByGroup();

        self::assertSame(['members' => 2, 'conversations' => 2, 'documents' => 1, 'requests' => 1], $counts[$alpha->id()]);
        self::assertSame(['members' => 1, 'conversations' => 2, 'documents' => 0, 'requests' => 0], $counts[$beta->id()], 'ses conversations, dont celle de la corbeille');
        self::assertSame(['members' => 0, 'conversations' => 0, 'documents' => 0, 'requests' => 0], $counts[$gamma->id()], 'un groupe vide n\'emporte rien');
    }

    #[Test]
    public function testAnEmptyDatabaseHasNoGroupToCount(): void
    {
        self::assertSame([], (new MysqlGroupImpactRepository($this->pdo))->countsByGroup());
    }
}
