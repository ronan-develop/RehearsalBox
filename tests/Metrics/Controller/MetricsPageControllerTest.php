<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Controller;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Service\AuthServiceInterface;
use App\Http\Request;
use App\Metrics\Controller\MetricsPageController;
use App\Metrics\MetricsAccess;
use App\Metrics\Report\HealthReportBuilder;
use App\Metrics\Report\Load\DegradationDetector;
use App\Metrics\Report\Load\LoadReportBuilder;
use App\Metrics\Report\Security\AnomalyDetector;
use App\Metrics\Report\Security\SecurityReportBuilder;
use App\Metrics\Report\Thresholds;
use App\Security\AuthGuard;
use App\Tests\Doubles\FakeMetricsReader;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MetricsPageControllerTest extends TestCase
{
    private function controller(?User $current, string $viewerEmail = 'owner@rehearsalbox.test'): MetricsPageController
    {
        $auth = new class ($current) implements AuthServiceInterface {
            public function __construct(private readonly ?User $user)
            {
            }

            public function attempt(string $email, string $plainPassword): ?User
            {
                return null;
            }

            public function currentUser(): ?User
            {
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

        return new MetricsPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            new AuthGuard($auth),
            new MetricsAccess($viewerEmail),
            new HealthReportBuilder(new FakeMetricsReader(), new Thresholds(), new MockClock('2026-10-07 12:30:00 UTC'), new \DateTimeZone('Europe/Paris')),
            new SecurityReportBuilder(new FakeMetricsReader(), new AnomalyDetector(), new Thresholds(), new MockClock('2026-10-07 12:30:00 UTC'), new \DateTimeZone('Europe/Paris')),
            new LoadReportBuilder(new FakeMetricsReader(), new DegradationDetector(), new Thresholds(), new MockClock('2026-10-07 12:30:00 UTC'), new \DateTimeZone('Europe/Paris')),
            new \DateTimeZone('Europe/Paris'),
        );
    }

    private function user(string $email, UserRole $role = UserRole::Admin): User
    {
        return new User(1, $email, 'hash', 'Test', $role, true, 0, null);
    }

    #[Test]
    public function testTheConfiguredOwnerSeesThePageWithCardsAndCharts(): void
    {
        $response = $this->controller($this->user('owner@rehearsalbox.test'))->index(new Request('GET', '/admin/metrics', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('<h1>Mesures</h1>', $response->body());
        self::assertStringContainsString('Disponibilité', $response->body());
        self::assertSame(5, substr_count($response->body(), '<figure class="rb-chart">'));
        self::assertStringContainsString('noindex', $response->body());
    }

    #[Test]
    public function testEveryoneElseGetsAnIndistinguishable404(): void
    {
        $request = new Request('GET', '/admin/metrics', [], [], []);
        $reference = $this->controller($this->user('owner@rehearsalbox.test'), 'nobody@rehearsalbox.test')->index($request);

        foreach ([
            'anonyme' => $this->controller(null),
            'autre administrateur' => $this->controller($this->user('other@rehearsalbox.test')),
            'musicien' => $this->controller($this->user('owner@rehearsalbox.test', UserRole::Musicien)),
            'aucune adresse configurée' => $this->controller($this->user('owner@rehearsalbox.test'), ''),
        ] as $who => $controller) {
            $response = $controller->index($request);
            self::assertSame(404, $response->statusCode(), $who);
            self::assertSame($reference->body(), $response->body(), $who . ' : même page que pour une route inexistante');
            self::assertStringNotContainsString('Mesures', $response->body(), $who);
        }
    }

    #[Test]
    public function testThePeriodIsChosenByTheQueryAndMarkedInTheLinks(): void
    {
        $response = $this->controller($this->user('owner@rehearsalbox.test'))->index(new Request('GET', '/admin/metrics', ['periode' => '7j'], [], []));

        self::assertStringContainsString('href="/admin/metrics?periode=7j" class="rb-metrics-period" aria-current="page"', $response->body());
        self::assertStringContainsString('Sur 7 jours', $response->body());
    }

    #[Test]
    public function testTheSecurityPageFollowsTheSameSingleAccountRule(): void
    {
        $request = new Request('GET', '/admin/metrics/security', [], [], []);

        $owner = $this->controller($this->user('owner@rehearsalbox.test'))->security($request);
        self::assertSame(200, $owner->statusCode());
        self::assertStringContainsString('Anomalies récentes', $owner->body());
        self::assertStringContainsString('aria-current="page">Sécurité', $owner->body());
        self::assertSame(4, substr_count($owner->body(), '<figure class="rb-chart">'));

        foreach ([null, $this->user('other@rehearsalbox.test'), $this->user('owner@rehearsalbox.test', UserRole::Musicien)] as $who) {
            self::assertSame(404, $this->controller($who)->security($request)->statusCode());
        }
    }

    #[Test]
    public function testTheLoadPageFollowsTheSameSingleAccountRule(): void
    {
        $request = new Request('GET', '/admin/metrics/load', [], [], []);

        $owner = $this->controller($this->user('owner@rehearsalbox.test'))->load($request);
        self::assertSame(200, $owner->statusCode());
        self::assertStringContainsString('Verdict de dégradation', $owner->body());
        self::assertStringContainsString('aria-current="page">Charge', $owner->body());

        foreach ([null, $this->user('other@rehearsalbox.test')] as $who) {
            self::assertSame(404, $this->controller($who)->load($request)->statusCode());
        }
    }
}
