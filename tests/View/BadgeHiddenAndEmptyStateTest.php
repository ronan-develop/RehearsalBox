<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #322 (issu de #182) : une règle globale pour un badge masqué, un seul gabarit pour l'état vide. */
final class BadgeHiddenAndEmptyStateTest extends TestCase
{
    private function read(string $path): string
    {
        return (string) file_get_contents(__DIR__ . '/../../' . $path);
    }

    #[Test]
    public function testAHiddenBadgeStaysHiddenWhateverItsDisplayRule(): void
    {
        self::assertMatchesRegularExpression('/\.rb-badge\[hidden\]\s*\{[^}]*display:\s*none/s', $this->read('public/assets/css/base.css'));
    }

    #[Test]
    public function testThePerPageHiddenBadgeOverridesAreGone(): void
    {
        $messages = $this->read('public/assets/css/pages/messages.css');
        $base = $this->read('public/assets/css/base.css');

        self::assertStringNotContainsString('.rb-messages-link .rb-badge[hidden]', $messages);
        self::assertStringNotContainsString('.rb-chat-archives .rb-badge[hidden]', $messages);
        self::assertStringNotContainsString('.rb-bottom-nav-badge[hidden]', $base);
    }

    #[Test]
    public function testTheEmptyStateIsOneSharedPartialUsedByTheThreeDecks(): void
    {
        $dashboard = $this->read('templates/dashboard/index.php');

        self::assertFileExists(__DIR__ . '/../../templates/partials/empty-state.php');
        self::assertSame(3, substr_count($dashboard, "partials/empty-state.php"), 'reçues, envoyées, archivées');
        self::assertStringNotContainsString('<div class="rb-exception-empty"', $dashboard, 'plus de bloc copié');
    }
}
