<?php

declare(strict_types=1);

namespace App\Tests\Operations\View;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Service\AuthServiceInterface;
use App\Metrics\MetricsAccess;
use App\Metrics\OperationsAccess;
use App\Operations\View\OpsLinkRenderer;
use App\Security\AuthGuard;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #400, #483 : l'onglet « Ops » de l'administration est montré à tout administrateur actif, jamais aux musiciens ni aux visiteurs ; le lien « Restauration » reste celui du seul propriétaire. */
final class OpsLinkRendererTest extends TestCase
{
    private function render(?User $current, ?UserRole $role, string $viewerEmail = 'owner@rehearsalbox.test', string $template = 'partials/admin-tabs'): string
    {
        $auth = new class ($current) implements AuthServiceInterface {
            public int $lookups = 0;

            public function __construct(private readonly ?User $user)
            {
            }

            public function attempt(string $email, string $plainPassword): ?User
            {
                return null;
            }

            public function currentUser(): ?User
            {
                ++$this->lookups;

                return $this->user;
            }

            public function refreshSession(User $user): void
            {
            }

            public function logout(): void
            {
            }

            public function groupsRequiringSelection(): array
            {
                return [];
            }

            public function selectActiveGroup(int $groupId): void
            {
            }
        };
        $renderer = new OpsLinkRenderer(new PhpTemplateRenderer(__DIR__ . '/../../../templates'), new AuthGuard($auth), new OperationsAccess(), new MetricsAccess($viewerEmail));

        return $renderer->render($template, ['currentUserRole' => $role, 'adminTab' => 'bookings', 'section' => 'backups']);
    }

    private function user(string $email, UserRole $role = UserRole::Admin): User
    {
        return new User(1, $email, 'hash', 'Test', $role, true, 0, null);
    }

    #[Test]
    public function testTheOwnerSeesTheOpsTab(): void
    {
        $html = $this->render($this->user('owner@rehearsalbox.test'), UserRole::Admin);

        self::assertMatchesRegularExpression('#<a href="/admin/ops" class="rb-admin-tab">\s*Ops\s*</a>#', $html);
    }

    #[Test]
    public function testAnotherActiveAdministratorSeesItToo(): void
    {
        self::assertMatchesRegularExpression('#<a href="/admin/ops" class="rb-admin-tab">\s*Ops\s*</a>#', $this->render($this->user('other@rehearsalbox.test'), UserRole::Admin));
    }

    #[Test]
    public function testItDoesNotDependOnAConfiguredOwner(): void
    {
        self::assertStringContainsString('/admin/ops', $this->render($this->user('other@rehearsalbox.test'), UserRole::Admin, ''));
    }

    #[Test]
    public function testOnlyTheOwnerGetsTheRestorationLinkInTheOperationsHeader(): void
    {
        $owner = $this->render($this->user('owner@rehearsalbox.test'), UserRole::Admin, 'owner@rehearsalbox.test', 'admin/ops/_header');
        $other = $this->render($this->user('other@rehearsalbox.test'), UserRole::Admin, 'owner@rehearsalbox.test', 'admin/ops/_header');
        $nobody = $this->render($this->user('other@rehearsalbox.test'), UserRole::Admin, '', 'admin/ops/_header');

        self::assertStringContainsString('href="/admin/restore"', $owner);
        self::assertStringNotContainsString('/admin/restore', $other);
        self::assertStringNotContainsString('/admin/restore', $nobody, 'sans propriétaire configuré, personne ne voit la restauration');
        self::assertStringContainsString('href="/admin/metrics"', $other, 'les mesures restent proposées à tout administrateur');
    }

    #[Test]
    public function testAMusicianOrAVisitorNeverSeesIt(): void
    {
        self::assertStringNotContainsString('/admin/ops', $this->render($this->user('owner@rehearsalbox.test', UserRole::Musicien), UserRole::Musicien));
        self::assertStringNotContainsString('/admin/ops', $this->render(null, null));
    }

    #[Test]
    public function testTemplatesWithoutARoleDoNotQueryTheAccount(): void
    {
        $auth = new class () implements AuthServiceInterface {
            public function attempt(string $email, string $plainPassword): ?User
            {
                return null;
            }

            public function currentUser(): ?User
            {
                throw new \LogicException('Le compte ne doit pas être interrogé pour un gabarit sans rôle.');
            }

            public function refreshSession(User $user): void
            {
            }

            public function logout(): void
            {
            }

            public function groupsRequiringSelection(): array
            {
                return [];
            }

            public function selectActiveGroup(int $groupId): void
            {
            }
        };
        $renderer = new OpsLinkRenderer(new PhpTemplateRenderer(__DIR__ . '/../../../templates'), new AuthGuard($auth), new OperationsAccess(), new MetricsAccess('owner@rehearsalbox.test'));

        self::assertStringNotContainsString('/admin/ops', $renderer->render('partials/admin-tabs', ['currentUserRole' => UserRole::Musicien, 'adminTab' => 'bookings']));
    }
}
