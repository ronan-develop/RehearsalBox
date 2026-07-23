<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\AvailabilityService;
use App\Service\Exception\SlotAlreadyClaimedException;
use App\Tests\RepositoryTestCase;

final class AvailabilityServiceTest extends RepositoryTestCase
{
    private function makeService(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $service = new AvailabilityService($exceptionRepository, $groupRepository);

        return [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository];
    }

    /** @return array{0: int, 1: int, 2: int} [slotId, releasingUserId, releasingGroupId] */
    private function createSlotAndUser(
        MysqlGroupRepository $groupRepository,
        MysqlRecurringSlotRepository $slotRepository,
        MysqlUserRepository $userRepository,
        string $email = 'alice@rehearsalbox.test',
    ): array {
        $group = $groupRepository->save(new Group(0, 'Groupe Test ' . $email, null, null));
        $slot = $slotRepository->save(
            new RecurringSlot(0, $group->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true)
        );
        $user = $userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        return [$slot->id(), $user->id(), $group->id()];
    }

    public function testClaimByMemberOfGroupSucceeds(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$slotId, $releasingUserId] = $this->createSlotAndUser($groupRepository, $slotRepository, $userRepository, 'alice@rehearsalbox.test');

        $exception = $exceptionRepository->createLiberation($slotId, new \DateTimeImmutable('2026-08-04'), $releasingUserId, null);

        $claimingGroup = $groupRepository->save(new Group(0, 'Groupe Revendicateur', null, null));
        $claimingUser = $userRepository->save(new User(
            id: 0,
            email: 'bob@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Bob',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $groupRepository->addMember($claimingGroup->id(), $claimingUser->id());

        $claimed = $service->claim($exception->id(), $claimingGroup->id(), $claimingUser->id());

        self::assertFalse($claimed->isLiberee());
        self::assertSame($claimingGroup->id(), $claimed->claimedByGroupId());
    }

    /**
     * IDOR (cf. plan §10.5) : un musicien qui n'appartient pas au groupe
     * revendicateur doit être rejeté même si le groupId fourni existe bel et
     * bien — l'appartenance est vérifiée côté serveur, jamais fait confiance
     * au payload client.
     */
    public function testClaimByUserNotMemberOfClaimingGroupThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$slotId, $releasingUserId] = $this->createSlotAndUser($groupRepository, $slotRepository, $userRepository, 'alice@rehearsalbox.test');

        $exception = $exceptionRepository->createLiberation($slotId, new \DateTimeImmutable('2026-08-04'), $releasingUserId, null);

        $otherGroup = $groupRepository->save(new Group(0, 'Groupe Auquel Bob N’Appartient Pas', null, null));
        $bob = $userRepository->save(new User(
            id: 0,
            email: 'bob@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Bob',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        // Bob n'est volontairement PAS ajouté comme membre de $otherGroup.

        $this->expectException(AccessDeniedException::class);

        $service->claim($exception->id(), $otherGroup->id(), $bob->id());
    }

    public function testClaimOnAlreadyClaimedExceptionThrowsSlotAlreadyClaimed(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$slotId, $releasingUserId] = $this->createSlotAndUser($groupRepository, $slotRepository, $userRepository, 'alice@rehearsalbox.test');

        $exception = $exceptionRepository->createLiberation($slotId, new \DateTimeImmutable('2026-08-04'), $releasingUserId, null);

        $claimingGroup = $groupRepository->save(new Group(0, 'Groupe Revendicateur', null, null));
        $claimingUser = $userRepository->save(new User(
            id: 0,
            email: 'bob@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Bob',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $groupRepository->addMember($claimingGroup->id(), $claimingUser->id());

        $service->claim($exception->id(), $claimingGroup->id(), $claimingUser->id());

        $this->expectException(SlotAlreadyClaimedException::class);

        $service->claim($exception->id(), $claimingGroup->id(), $claimingUser->id());
    }

    public function testClaimOnUnknownExceptionThrowsSlotAlreadyClaimed(): void
    {
        [$service, $groupRepository, , , $userRepository] = $this->makeService();

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $user = $userRepository->save(new User(
            id: 0,
            email: 'chris@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Chris',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $groupRepository->addMember($group->id(), $user->id());

        $this->expectException(SlotAlreadyClaimedException::class);

        $service->claim(9999, $group->id(), $user->id());
    }

    public function testFindLiberatedBetweenDelegatesToRepository(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$slotId, $releasingUserId] = $this->createSlotAndUser($groupRepository, $slotRepository, $userRepository, 'alice@rehearsalbox.test');

        $exceptionRepository->createLiberation($slotId, new \DateTimeImmutable('2026-08-04'), $releasingUserId, null);

        $found = $service->findLiberatedBetween(
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );

        self::assertCount(1, $found);
    }
}
