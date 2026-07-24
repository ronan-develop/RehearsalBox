<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PageController;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Service\GroupService;
use App\Service\SlotService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\View\PhpTemplateRenderer;

final class PageControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session);
        $authGuard = new AuthGuard($authService);
        $availabilityService = new AvailabilityService($exceptionRepository, $groupRepository, $slotRepository);
        $slotService = new SlotService($slotRepository, $groupRepository);
        $groupService = new GroupService($groupRepository, $userRepository);

        $controller = new PageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            $authGuard,
            $availabilityService,
            $groupRepository,
            $slotService,
            $groupService,
        );

        return [$controller, $groupRepository, $slotService, $userRepository, $authService];
    }

    private function createLoggedInUser(MysqlUserRepository $userRepository, AuthService $authService): User
    {
        $user = $userRepository->save(new User(
            id: 0,
            email: 'musicien@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Musicien Test',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $authService->attempt('musicien@rehearsalbox.test', 'password');

        return $user;
    }

    public function testDashboardIncludesPlanningSliderWithFixedSlots(): void
    {
        [$controller, $groupRepository, $slotService, $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $slotService->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $response = $controller->dashboard();

        self::assertStringContainsString('data-planning-slider', $response->body());
        self::assertStringContainsString('Groupe Test', $response->body());
    }

    public function testDashboardShowsNoPlanningSliderWhenNoFixedSlots(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        self::assertStringNotContainsString('data-planning-slider', $response->body());
    }
}
