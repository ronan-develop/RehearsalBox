<?php

declare(strict_types=1);

namespace App\Tests\Operations\View;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Service\AuthServiceInterface;
use App\Metrics\MetricsAccess;
use App\Operations\View\OpsLinkRenderer;
use App\Security\AuthGuard;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #400 : le lien « Ops » de la barre du bas n'est montré qu'au propriétaire, jamais aux autres administrateurs ni aux musiciens. */
final class OpsLinkRendererTest extends TestCase
{
    private function render(?User $current, ?UserRole $role, string $viewerEmail = 'owner@rehearsalbox.test'): string
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
        $renderer = new OpsLinkRenderer(new PhpTemplateRenderer(__DIR__ . '/../../../templates'), new AuthGuard($auth), new MetricsAccess($viewerEmail));

        return $renderer->render('partials/nav', ['currentUserRole' => $role]);
    }

    private function user(string $email, UserRole $role = UserRole::Admin): User
    {
        return new User(1, $email, 'hash', 'Test', $role, true, 0, null);
    }

    #[Test]
    public function testTheOwnerSeesTheOpsLink(): void
    {
        $html = $this->render($this->user('owner@rehearsalbox.test'), UserRole::Admin);

        self::assertStringContainsString('href="/admin/ops"', $html);
        self::assertStringContainsString('Ops', $html);
    }

    #[Test]
    public function testAnotherAdministratorDoesNotSeeIt(): void
    {
        self::assertStringNotContainsString('/admin/ops', $this->render($this->user('other@rehearsalbox.test'), UserRole::Admin));
    }

    #[Test]
    public function testWithoutAConfiguredOwnerNobodySeesIt(): void
    {
        self::assertStringNotContainsString('/admin/ops', $this->render($this->user('owner@rehearsalbox.test'), UserRole::Admin, ''));
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
        $renderer = new OpsLinkRenderer(new PhpTemplateRenderer(__DIR__ . '/../../../templates'), new AuthGuard($auth), new MetricsAccess('owner@rehearsalbox.test'));

        self::assertStringNotContainsString('/admin/ops', $renderer->render('partials/nav', ['currentUserRole' => UserRole::Musicien]));
    }
}
