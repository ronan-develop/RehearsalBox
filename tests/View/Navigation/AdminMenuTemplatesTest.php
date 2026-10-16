<?php

declare(strict_types=1);

namespace App\Tests\View\Navigation;

use App\Account\Entity\UserRole;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #481 : menu du bas réduit à « Admin » (vers les réservations), créneaux récurrents devenus un onglet d'administration. */
final class AdminMenuTemplatesTest extends TestCase
{
    private function renderer(): PhpTemplateRenderer
    {
        return new PhpTemplateRenderer(__DIR__ . '/../../../templates');
    }

    #[Test]
    public function testTheBottomMenuHasASingleAdminEntryCarryingThePendingBadge(): void
    {
        $html = $this->renderer()->render('partials/nav', ['currentUserRole' => UserRole::Admin]);

        self::assertMatchesRegularExpression('#<a href="/admin/bookings" class="rb-bottom-nav-link">Admin\s*<span[^>]*data-bookings-badge#', $html);
        self::assertStringNotContainsString('href="/admin/slots"', $html);
        self::assertStringNotContainsString('href="/admin/groups"', $html);
        self::assertStringNotContainsString('>Groupes<', $html);
    }

    #[Test]
    public function testTheTabsListFiveSectionsWithReservationsFirstAndMarkTheCurrentOne(): void
    {
        foreach (['bookings', 'slots', 'groups', 'users', 'assemblies'] as $tab) {
            $html = $this->renderer()->render('partials/admin-tabs', ['adminTab' => $tab]);

            preg_match_all('#<a href="(/admin/[a-z]+)" class="rb-admin-tab"( aria-current="page")?#', $html, $found, PREG_SET_ORDER);
            self::assertSame(['/admin/bookings', '/admin/slots', '/admin/groups', '/admin/users', '/admin/assemblies'], array_column($found, 1));
            $current = array_values(array_filter($found, static fn (array $m): bool => isset($m[2])));
            self::assertCount(1, $current, "un seul onglet courant pour « {$tab} »");
        }
    }

    #[Test]
    public function testTheReservationsTabCarriesTheBadgeToo(): void
    {
        $html = $this->renderer()->render('partials/admin-tabs', ['adminTab' => 'slots']);

        self::assertSame(1, substr_count($html, 'data-bookings-badge'));
        self::assertMatchesRegularExpression('#href="/admin/bookings"[^>]*>\s*Réservations\s*<span[^>]*data-bookings-badge#', $html);
    }

    #[Test]
    public function testTheSlotsPageShowsTheAdminTabsWithItsOwnTabCurrent(): void
    {
        $html = $this->renderer()->render('admin/slots/index', ['csrfToken' => 't', 'groups' => [], 'slots' => [], 'currentUserRole' => UserRole::Admin]);

        self::assertStringContainsString('<a href="/admin/slots" class="rb-admin-tab" aria-current="page"', $html);
    }
}
