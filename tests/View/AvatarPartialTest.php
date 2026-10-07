<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #324 (issu de #182) : une seule pastille d'initiales pour les cartes de demande, l'en-tête et la messagerie. */
final class AvatarPartialTest extends TestCase
{
    private function render(array $vars): string
    {
        return (new PhpTemplateRenderer(__DIR__ . '/../../templates'))->render('partials/avatar', $vars);
    }

    private function css(string $path): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/' . $path);
    }

    #[Test]
    public function testTheAvatarShowsTheInitialsInAnEscapedDecorativeCircle(): void
    {
        $html = $this->render(['avatarInitials' => 'A<B', 'avatarClass' => 'rb-chat-avatar', 'avatarColor' => null, 'avatarTitle' => null]);

        self::assertStringContainsString('class="rb-avatar rb-chat-avatar" aria-hidden="true">A&lt;B</span>', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('title=', $html);
    }

    #[Test]
    public function testTheGroupColourAndTitleAreEscapedAttributes(): void
    {
        $html = $this->render(['avatarInitials' => 'AL', 'avatarClass' => '', 'avatarColor' => '#b5654a', 'avatarTitle' => 'The "Office"']);

        self::assertStringContainsString('style="--group-color: #b5654a"', $html);
        self::assertStringContainsString('title="The &quot;Office&quot;"', $html);
    }

    #[Test]
    public function testOneSharedAvatarRuleReplacesTheThreeCopies(): void
    {
        $base = $this->css('base.css');

        self::assertMatchesRegularExpression('/\.rb-avatar\s*\{[^}]*border-radius:\s*50%[^}]*var\(--group-color,\s*var\(--rb-accent-2\)\)/s', $base);
        self::assertMatchesRegularExpression('/\.rb-avatar--sm\s*\{[^}]*--avatar-size:\s*32px/s', $base);
        self::assertStringNotContainsString('.rb-exception-card-avatar {', $this->css('pages/dashboard.css'));
        self::assertStringNotContainsString('.rb-chat-avatar {', $this->css('pages/messages.css'));
    }
}
