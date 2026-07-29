<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PageController;
use App\Entity\Enum\ExceptionDirection;
use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlGroupDocumentRepository;
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
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session, $groupRepository);
        $authGuard = new AuthGuard($authService);
        $availabilityService = new AvailabilityService($exceptionRepository, $groupRepository, $slotRepository);
        $slotService = new SlotService($slotRepository, $groupRepository, $exceptionRepository);
        $groupService = new GroupService($groupRepository, $userRepository);
        $groupDocumentRepository = new MysqlGroupDocumentRepository($this->pdo);

        $controller = new PageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            $authGuard,
            $availabilityService,
            $groupRepository,
            $slotService,
            $groupService,
            $groupDocumentRepository,
        );

        return [$controller, $groupRepository, $slotService, $userRepository, $authService, $exceptionRepository, $slotRepository];
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
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slotService->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $response = $controller->dashboard();

        self::assertStringContainsString('data-planning-slider', $response->body());
        self::assertStringContainsString('Groupe Test', $response->body());
        self::assertStringContainsString('data-contact-group-id="' . $group->id() . '"', $response->body());
        self::assertStringContainsString('data-contact-group-slug="groupe-test"', $response->body());
        self::assertStringNotContainsString('contact@example.test', $response->body());
    }

    public function testDashboardExposesCurrentUserGroupRoleOnPlanningCard(): void
    {
        [$controller, $groupRepository, $slotService, $userRepository, $authService] = $this->makeController();
        $user = $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id(), GroupUserRole::Gestionnaire);
        $slotService->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $response = $controller->dashboard();

        self::assertStringContainsString('data-current-user-group-role="gestionnaire"', $response->body());
    }

    public function testDashboardShowsNoPlanningSliderWhenNoFixedSlots(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        self::assertStringNotContainsString('data-planning-slider', $response->body());
    }

    public function testDashboardShowsNoExceptionalSliderWhenNoOccasionalSlots(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        self::assertStringNotContainsString('data-planning-slider-exceptional', $response->body());
    }

    public function testDashboardShowsExceptionalSliderWithNonClickableCardsForAcceptedOccasionalSlot(): void
    {
        [$controller, $groupRepository, $slotService, $userRepository, $authService, $exceptionRepository] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));
        $holderSlot = $slotService->create($holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null, 'contact@example.test'));
        $requester = $userRepository->save(new User(
            id: 0,
            email: 'requester@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Requester',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $monday = (new \DateTimeImmutable('today'))->modify('monday this week');
        $exception = $exceptionRepository->createRequest($holderSlot->id(), $monday, $requestingGroup->id(), $requester->id(), null);
        $exceptionRepository->respond($exception->id(), true, $requester->id());

        $response = $controller->dashboard();

        self::assertStringContainsString('data-planning-slider-exceptional', $response->body());
        self::assertStringContainsString('Groupe Demandeur', $response->body());
        self::assertStringContainsString($monday->format('d/m/Y'), $response->body());

        $exceptionalSection = substr(
            $response->body(),
            (int) strpos($response->body(), 'data-planning-slider-exceptional'),
        );
        $cardStart = strpos($exceptionalSection, 'Groupe Demandeur');
        $cardOpenTag = substr($exceptionalSection, 0, $cardStart);
        self::assertStringNotContainsString('role="button"', $cardOpenTag);
    }

    public function testDashboardHidesNavLinksForNonAdmin(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        self::assertStringNotContainsString('<nav', $response->body());
        self::assertStringNotContainsString('href="/admin', $response->body());
        self::assertStringContainsString('data-logout', $response->body());
    }

    public function testDashboardKeepsNavForAdmin(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $userRepository->save(new User(
            id: 0,
            email: 'admin@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Admin Test',
            role: UserRole::Admin,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $response = $controller->dashboard();

        self::assertStringContainsString('<nav', $response->body());
        self::assertStringContainsString('href="/admin/slots"', $response->body());
        self::assertStringContainsString('href="/admin/groups"', $response->body());
        self::assertStringContainsString('data-logout', $response->body());
    }

    public function testDashboardShowsPendingRequestsForAdminWhoIsAlsoGroupMember(): void
    {
        [$controller, $groupRepository, $slotService, $userRepository, $authService, $exceptionRepository, $slotRepository] = $this->makeController();

        $admin = $userRepository->save(new User(
            id: 0,
            email: 'admin@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Admin Test',
            role: UserRole::Admin,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Admin', null, null, 'contact@example.test'));
        $groupRepository->addMember($holderGroup->id(), $admin->id());
        $slot = $slotService->create($holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null, 'contact@example.test'));
        $requester = $userRepository->save(new User(
            id: 0,
            email: 'bob@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Bob',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $groupRepository->addMember($requestingGroup->id(), $requester->id());
        $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('2026-08-04'), $requestingGroup->id(), $requester->id(), 'Concert samedi');

        $response = $controller->dashboard();

        self::assertStringContainsString('data-exception-deck', $response->body());
        self::assertStringContainsString('Concert samedi', $response->body());
        self::assertStringContainsString('Groupe Demandeur', $response->body());
    }

    public function testDashboardMergesReceivedAndSentExceptionsSortedByCreatedAtDescending(): void
    {
        [$controller, $groupRepository, $slotService, $userRepository, $authService, $exceptionRepository] = $this->makeController();

        $user = $this->createLoggedInUser($userRepository, $authService);

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));
        $groupRepository->addMember($holderGroup->id(), $user->id());
        $slot = $slotService->create($holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $otherGroup = $groupRepository->save(new Group(0, 'Autre Groupe', null, null, 'contact@example.test'));
        $groupRepository->addMember($otherGroup->id(), $user->id());

        // Demande reçue par $holderGroup (dont $user est membre).
        $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('2026-08-04'), $otherGroup->id(), $user->id(), 'Demande reçue');

        // Demande envoyée par $otherGroup (dont $user est aussi membre) vers un autre créneau.
        $otherHolderGroup = $groupRepository->save(new Group(0, 'Groupe Tiers', null, null, 'contact@example.test'));
        $otherSlot = $slotService->create($otherHolderGroup->id(), Weekday::Wednesday, '18:00:00', '20:00:00');
        $exceptionRepository->createRequest($otherSlot->id(), new \DateTimeImmutable('2026-08-05'), $otherGroup->id(), $user->id(), 'Demande envoyée');

        $response = $controller->dashboard();

        self::assertStringContainsString('data-exception-deck', $response->body());
        $recuePosition = strpos($response->body(), 'Demande reçue');
        $envoyeePosition = strpos($response->body(), 'Demande envoyée');
        self::assertNotFalse($recuePosition);
        self::assertNotFalse($envoyeePosition);
        self::assertGreaterThan($recuePosition, $envoyeePosition, 'La demande la plus récente (envoyée en second) doit apparaître en premier.');
    }

    public function testDashboardHidesExceptionDeckSectionWhenListIsEmpty(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        self::assertStringNotContainsString('data-exception-deck', $response->body());
    }

    public function testGroupSpaceByMemberShowsGroupName(): void
    {
        [$controller, $groupRepository, , $userRepository, $authService] = $this->makeController();
        $user = $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-test/space', [], [], []), 'groupe-test');

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Groupe Test', $response->body());
    }

    public function testGroupSpaceListsGroupDocuments(): void
    {
        [$controller, $groupRepository, , $userRepository, $authService] = $this->makeController();
        $user = $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id(), GroupUserRole::Gestionnaire);
        $documentRepository = new \App\Repository\MysqlGroupDocumentRepository($this->pdo);
        $documentRepository->save(new \App\Entity\GroupDocument(0, $group->id(), 'fiche technique.pdf', 'abc123.pdf', 'application/pdf', 100, $user->id()));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-test/space', [], [], []), 'groupe-test');

        self::assertStringContainsString('fiche technique.pdf', $response->body());
    }

    public function testGroupSpaceIsPubliclyAccessibleWithoutLogin(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $groupRepository->save(new Group(0, 'Groupe Public', null, null, 'contact@example.test'));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-public/space', [], [], []), 'groupe-public');

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Groupe Public', $response->body());
    }

    public function testGroupSpaceResolvesGroupBySlug(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $groupRepository->save(new Group(0, 'Black Sabbath Tribute', null, null, 'contact@example.test'));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/black-sabbath-tribute/space', [], [], []), 'black-sabbath-tribute');

        self::assertStringContainsString('Black Sabbath Tribute', $response->body());
    }

    public function testGroupSpaceHidesDocumentsSectionForNonMember(): void
    {
        [$controller, $groupRepository, , $userRepository, $authService] = $this->makeController();
        $manager = $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Tiers', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $documentRepository = new \App\Repository\MysqlGroupDocumentRepository($this->pdo);
        $documentRepository->save(new \App\Entity\GroupDocument(0, $group->id(), 'confidentiel.pdf', 'abc123.pdf', 'application/pdf', 100, $manager->id()));

        $stranger = $this->createUser($userRepository, 'stranger@rehearsalbox.test');
        $authService->attempt('stranger@rehearsalbox.test', 'password');

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-tiers/space', [], [], []), 'groupe-tiers');

        self::assertStringNotContainsString('confidentiel.pdf', $response->body());
    }

    public function testGroupSpaceHidesDocumentsSectionWhenNotAuthenticated(): void
    {
        [$controller, $groupRepository, , $userRepository] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Public', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'manager@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $documentRepository = new \App\Repository\MysqlGroupDocumentRepository($this->pdo);
        $documentRepository->save(new \App\Entity\GroupDocument(0, $group->id(), 'confidentiel.pdf', 'abc123.pdf', 'application/pdf', 100, $manager->id()));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-public/space', [], [], []), 'groupe-public');

        self::assertStringNotContainsString('confidentiel.pdf', $response->body());
    }

    public function testGroupSpaceHidesDocumentsSectionEntirelyForVisitor(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $groupRepository->save(new Group(0, 'Groupe Public', null, null, 'contact@example.test'));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-public/space', [], [], []), 'groupe-public');

        self::assertStringNotContainsString('data-documents-list', $response->body());
    }

    public function testGroupSpaceShowsContactButtonForVisitor(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Public', null, null, 'contact@example.test'));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-public/space', [], [], []), 'groupe-public');

        self::assertStringContainsString('data-contact-group-id="' . $group->id() . '"', $response->body());
    }

    public function testGroupSpaceHidesContactButtonForMember(): void
    {
        [$controller, $groupRepository, , $userRepository, $authService] = $this->makeController();
        $user = $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-test/space', [], [], []), 'groupe-test');

        self::assertStringNotContainsString('Contacter ce groupe', $response->body());
    }

    public function testGroupSpaceByUnknownSlugThrowsAccessDenied(): void
    {
        [$controller] = $this->makeController();

        $this->expectException(\App\Security\Exception\AccessDeniedException::class);

        $controller->groupSpace(new \App\Http\Request('GET', '/groups/inconnu/space', [], [], []), 'inconnu');
    }

    private function createUser(MysqlUserRepository $userRepository, string $email): User
    {
        return $userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: $email,
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }
}
