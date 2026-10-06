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
use App\Presenter\PlanningDays;
use App\Presenter\PlanningView;
use App\Security\AuthGuard;
use Symfony\Component\Clock\MockClock;
use App\Security\CsrfTokenManager;
use App\Tests\Support\FastPasswordHasher;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Service\GroupService;
use App\Service\SlotService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;

final class PageControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new FastPasswordHasher(), $session, $groupRepository);
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
            new PlanningView($slotService, new PlanningDays(), new MockClock('2026-10-06 12:00:00'), new \DateTimeZone('Europe/Paris')),
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

    #[Test]

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

    #[Test]
    public function testPlanningIsGroupedByDayStartingTodayWithEachDayHeading(): void
    {
        [$controller, $groupRepository, $slotService, $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slotService->create($group->id(), Weekday::Monday, '18:00:00', '20:00:00');
        $slotService->create($group->id(), Weekday::Wednesday, '18:00:00', '20:00:00');
        $slotService->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00'); // aujourd'hui : mardi 6 octobre 2026

        $body = $controller->dashboard()->body();

        preg_match_all('/data-planning-day="(\d)"/', $body, $days);
        self::assertSame(['1', '2', '0'], $days[1], 'mardi (aujourd\'hui), mercredi, puis lundi de la semaine suivante');
        self::assertSame(1, substr_count($body, 'data-today'), 'un seul jour est marqué aujourd\'hui');
        self::assertLessThan(
            strpos($body, 'data-planning-day="2"'),
            strpos($body, 'data-today'),
            'le marqueur est sur le premier jour',
        );
        self::assertStringNotContainsString('aria-hidden="true" style="display: contents;"', $body, 'plus de copie dupliquée dans le HTML (créée par le JS si besoin)');
    }

    #[Test]

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

    #[Test]

    public function testDashboardExposesPrimaryGroupNameAndInitialsWhenUserBelongsToOneGroup(): void
    {
        [$controller, $groupRepository, , $userRepository, $authService] = $this->makeController();
        $user = $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id(), GroupUserRole::Gestionnaire);

        $response = $controller->dashboard();

        self::assertStringContainsString('<span>Groupe Test</span>', $response->body());
        self::assertStringContainsString('rb-dashboard-avatar" aria-hidden="true">MT<', $response->body());
    }

    #[Test]

    public function testDashboardExposesFirstGroupAlphabeticallyWhenUserBelongsToMultipleGroups(): void
    {
        [$controller, $groupRepository, , $userRepository, $authService] = $this->makeController();
        $user = $this->createLoggedInUser($userRepository, $authService);
        $groupZ = $groupRepository->save(new Group(0, 'Zebra', null, null, 'contact-z@example.test'));
        $groupA = $groupRepository->save(new Group(0, 'Alpha', null, null, 'contact-a@example.test'));
        $groupRepository->addMember($groupZ->id(), $user->id(), GroupUserRole::Membre);
        $groupRepository->addMember($groupA->id(), $user->id(), GroupUserRole::Membre);

        $response = $controller->dashboard();

        self::assertStringContainsString('<span>Alpha</span>', $response->body());
    }

    #[Test]

    public function testDashboardExposesNoPrimaryGroupWhenUserBelongsToNoGroup(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        self::assertStringContainsString('<span>Admin local</span>', $response->body());
    }

    #[Test]

    public function testDashboardShowsNoPlanningSliderWhenNoFixedSlots(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        self::assertStringNotContainsString(' data-planning-slider>', $response->body());
    }

    #[Test]

    public function testDashboardHidesExceptionalSliderWhenNoOccasionalSlots(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        // La section reste dans le DOM (marquée vide : masquée sur bureau, message sur mobile) plutôt
        // qu'absente : #79 a besoin de la remplir dynamiquement après une acceptation, sans reload complet.
        self::assertStringContainsString('data-planning-slider-exceptional', $response->body());
        $sliderPosition = strpos($response->body(), 'data-planning-slider-exceptional');
        $sectionStart = strrpos(substr($response->body(), 0, $sliderPosition), '<section');
        $sectionOpenTag = substr($response->body(), $sectionStart, $sliderPosition - $sectionStart);
        self::assertStringContainsString('rb-planning-section--empty', $sectionOpenTag);
        self::assertStringContainsString('Aucun créneau exceptionnel cette semaine.', $response->body());
    }

    #[Test]

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

    #[Test]

    public function testDashboardHidesNavLinksForNonAdmin(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $response = $controller->dashboard();

        self::assertStringNotContainsString('<nav', $response->body());
        self::assertStringNotContainsString('href="/admin', $response->body());
        self::assertStringContainsString('data-logout', $response->body());
    }

    #[Test]

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

    #[Test]

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
        $seen = new \DateTimeImmutable('+7 days');
        $exceptionRepository->createRequest($slot->id(), $seen, $requestingGroup->id(), $requester->id(), 'Concert samedi');

        $response = $controller->dashboard();

        self::assertStringContainsString('data-exception-deck', $response->body());
        self::assertStringContainsString('Concert samedi', $response->body());
        self::assertStringContainsString('Groupe Demandeur', $response->body());
        // La date que le titulaire a sous les yeux part avec sa réponse (#221) : sur « Accepter » comme sur « Refuser ».
        self::assertSame(2, substr_count($response->body(), 'data-occurrence-date="' . $seen->format('Y-m-d') . '"'));
    }

    /**
     * Deux demandes envoyées par le groupe de l'utilisateur vers le même créneau
     * d'un autre groupe, avec des created_at fixés : [ancienne, récente].
     *
     * @return array{0: PageController, 1: array{0: int, 1: int}}
     */
    private function sentRequestsWithCreatedAt(string $olderCreatedAt, string $newerCreatedAt): array
    {
        [$controller, $groupRepository, $slotService, $userRepository, $authService, $exceptionRepository] = $this->makeController();
        $user = $this->createLoggedInUser($userRepository, $authService);

        $ownGroup = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null, 'contact@example.test'));
        $groupRepository->addMember($ownGroup->id(), $user->id());
        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));
        $slot = $slotService->create($holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $older = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $ownGroup->id(), $user->id(), 'Demande ancienne');
        $newer = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+14 days'), $ownGroup->id(), $user->id(), 'Demande récente');

        $update = $this->pdo->prepare('UPDATE slot_exceptions SET created_at = :created_at WHERE id = :id');
        $update->execute(['created_at' => $olderCreatedAt, 'id' => $older->id()]);
        $update->execute(['created_at' => $newerCreatedAt, 'id' => $newer->id()]);

        return [$controller, [$older->id(), $newer->id()]];
    }

    #[Test]

    public function testDashboardListsMostRecentSentRequestFirst(): void
    {
        [$controller] = $this->sentRequestsWithCreatedAt('2026-01-01 10:00:00', '2026-01-02 10:00:00');

        $body = $controller->dashboard()->body();

        self::assertNotFalse(strpos($body, 'Demande ancienne'));
        self::assertNotFalse(strpos($body, 'Demande récente'));
        self::assertLessThan(strpos($body, 'Demande ancienne'), strpos($body, 'Demande récente'), 'La demande la plus récente doit apparaître en premier.');
    }

    #[Test]

    public function testDashboardBreaksCreatedAtTiesByDescendingId(): void
    {
        // Même seconde : l'ordre ne doit dépendre ni de l'insertion ni du hasard.
        [$controller] = $this->sentRequestsWithCreatedAt('2026-01-01 10:00:00', '2026-01-01 10:00:00');

        $body = $controller->dashboard()->body();

        self::assertLessThan(strpos($body, 'Demande ancienne'), strpos($body, 'Demande récente'), 'À created_at égal, l\'identifiant le plus grand (créé en dernier) passe en premier.');
    }

    #[Test]

    public function testDashboardShowsExceptionDeckWithEmptyStatesWhenThereIsNoRequest(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $body = $controller->dashboard()->body();

        self::assertStringContainsString('Demandes de créneau', $body);
        self::assertStringContainsString('data-exception-deck', $body);
        self::assertStringContainsString('Aucune demande reçue en attente.', $body);
        self::assertStringContainsString('Aucune demande envoyée en attente.', $body);
        self::assertStringContainsString('Aucune demande archivée.', $body);
    }

    #[Test]

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

    #[Test]

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

    #[Test]

    public function testGroupSpaceIsPubliclyAccessibleWithoutLogin(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $groupRepository->save(new Group(0, 'Groupe Public', null, null, 'contact@example.test'));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-public/space', [], [], []), 'groupe-public');

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Groupe Public', $response->body());
    }

    #[Test]

    public function testGroupSpaceResolvesGroupBySlug(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $groupRepository->save(new Group(0, 'Black Sabbath Tribute', null, null, 'contact@example.test'));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/black-sabbath-tribute/space', [], [], []), 'black-sabbath-tribute');

        self::assertStringContainsString('Black Sabbath Tribute', $response->body());
    }

    #[Test]

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

    #[Test]

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

    #[Test]

    public function testGroupSpaceHidesDocumentsSectionEntirelyForVisitor(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $groupRepository->save(new Group(0, 'Groupe Public', null, null, 'contact@example.test'));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-public/space', [], [], []), 'groupe-public');

        self::assertStringNotContainsString('data-documents-list', $response->body());
    }

    #[Test]

    public function testGroupSpaceShowsContactButtonForVisitor(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Public', null, null, 'contact@example.test'));

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-public/space', [], [], []), 'groupe-public');

        self::assertStringContainsString('href="/messages/new/' . $group->id() . '"', $response->body());
    }

    #[Test]

    public function testGroupSpaceHidesContactButtonForMember(): void
    {
        [$controller, $groupRepository, , $userRepository, $authService] = $this->makeController();
        $user = $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $response = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-test/space', [], [], []), 'groupe-test');

        self::assertStringNotContainsString('Contacter ce groupe', $response->body());
    }

    #[Test]

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

    // --- Messagerie (#153) ------------------------------------------------------------------------

    #[Test]
    public function testDashboardLinksToTheMessagesPageWithAnUnreadBadgeSlot(): void
    {
        [$controller, , , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $body = $controller->dashboard()->body();

        self::assertStringContainsString('href="/messages"', $body);
        self::assertStringContainsString('data-messages-link-badge', $body);
        self::assertStringNotContainsString('data-thread-overlay', $body, 'le fil vit sur sa propre page');
    }

    #[Test]
    public function testNoContactModalAnywhereAndTheGroupSpaceLinksToTheNewConversationPage(): void
    {
        [$controller, $groupRepository, , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Public', null, null, 'public@example.test'));

        $space = $controller->groupSpace(new \App\Http\Request('GET', '/groups/groupe-public/space', [], [], []), 'groupe-public')->body();
        $dashboard = $controller->dashboard()->body();

        self::assertStringContainsString('href="/messages/new/' . $group->id() . '"', $space);
        foreach ([$space, $dashboard] as $body) {
            self::assertStringNotContainsString('data-contact-modal-overlay', $body, 'on écrit dans une page de conversation, plus dans une modale');
            self::assertStringNotContainsString('data-contact-form', $body);
        }
    }

    #[Test]
    public function testAHostileGroupColourStoredInTheDatabaseNeverReachesTheStyleAttribute(): void
    {
        [$controller, $groupRepository, $slotService, $userRepository, $authService] = $this->makeController();
        $admin = $userRepository->save(new User(0, 'admin@rehearsalbox.test', password_hash('password', PASSWORD_DEFAULT), 'Admin', UserRole::Admin, true, 0, null));
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $holder = $groupRepository->save(new Group(0, 'Groupe Admin', null, null, 'contact@example.test'));
        $groupRepository->addMember($holder->id(), $admin->id());
        $slot = $slotService->create($holder->id(), Weekday::Tuesday, '18:00:00', '20:00:00');
        $requester = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null, 'contact@example.test'));
        $bob = $userRepository->save(new User(0, 'bob@rehearsalbox.test', password_hash('password', PASSWORD_DEFAULT), 'Bob', UserRole::Musicien, true, 0, null));
        $groupRepository->addMember($requester->id(), $bob->id());
        // La validation à l'écriture (#224) l'interdirait : on l'écrit directement, comme le ferait un ancien enregistrement ou un accès à la base.
        $this->pdo->prepare('UPDATE `groups` SET color_hex = ? WHERE id = ?')->execute([';top:0;', $requester->id()]);
        (new \App\Repository\MysqlSlotExceptionRepository($this->pdo))->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requester->id(), $bob->id(), 'Concert');

        $body = $controller->dashboard()->body();

        self::assertStringContainsString('data-exception-deck', $body);
        self::assertStringNotContainsString(';top:0;', $body, 'du CSS venu de la base n\'arrive jamais dans la page');
        self::assertStringContainsString('--group-color: var(--rb-accent);', $body, 'repli sur la couleur du thème');
    }
}
